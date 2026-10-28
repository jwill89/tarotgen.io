#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Build and deploy the TarotGen.io frontend and/or PHP backend to the droplet.

.DESCRIPTION
    The live site at <WebRoot> (Apache + PHP) has a flat layout that mirrors this
    repo's runtime files. This script flattens the repo into that layout WITHOUT
    ever touching the server-side data (.env + the database, which live outside
    the web root in TAROT_DATA_DIR, and assets/decks/).

    Each target is packed into one tarball (a "bundle") with the shell that
    installs it. A run uploads all its bundles in ONE pscp call, to the SSH user's
    home directory (outside the web root), and installs them in ONE plink call:
    checksums verified first, then each bundle unpacked into a staging dir and
    swapped in. Two SSH connections whatever the target, so `ufw limit ssh` (which
    drops the IP after 6 connections in 30s) never needs a pause, and one archive
    replaces the per-file round trips that made a 1 MB upload take ~20s.

    -Target frontend (default):
        Builds the SPA (vite) -> dist/, then swaps it in: live "dist" -> "dist.old"
        (rollback backup, overwritten each deploy), new build -> "dist".

    -Target backend:
        Packs the runtime PHP payload (api/, includes/, scripts/, og.php,
        openapi.json, composer.json, composer.lock, .htaccess) — NOT vendor/, db/,
        .env, assets/. Archives the current backend to .deploy/backend-prev.tgz
        (rollback), swaps the new files into place, and — only if composer.lock
        changed — runs `composer install --no-dev --optimize-autoloader`.

    -Target both: frontend and backend, in one upload and one install.

    -Target plugin: builds the FFXIV plugin and publishes it to <WebRoot>/plugin.

    Nothing goes live unless every bundle of the run built and uploaded intact.
    Unpacked files get the server's usual modes (644 files, 755 dirs, owned by the
    SSH user) and the extraction time as mtime, exactly as the old per-file pscp
    upload left them — Windows tar records 0666 files and read-only dirs, which
    are deliberately not kept.

    Uses PuTTY's pscp/plink so the DigitalOcean .ppk key works directly. pscp/plink
    run in batch mode, so a passphrase-protected .ppk must be loaded into Pageant
    first:  pageant.exe "<KeyPath>"  (enter the passphrase once per Windows session).
    A connection that gets rate-limited is retried once after a 35s wait.

.PARAMETER Target
    What to deploy: frontend (default), backend, both, or plugin.

.PARAMETER VpsHost
    Droplet IP or hostname. Defaults to the production droplet.

.PARAMETER VpsUser
    SSH user (default "root").

.PARAMETER KeyPath
    Path to the PuTTY .ppk private key.

.PARAMETER WebRoot
    Apache DocumentRoot on the host (contains "dist", api/, includes/, ...).

.PARAMETER SkipBuild
    Frontend only: deploy the existing dist/ without rebuilding.

.PARAMETER NoBackup
    Remove the rollback backup (dist.old / .deploy/backend-prev.tgz) after a
    successful deploy.

.EXAMPLE
    .\scripts\deploy.ps1                 # frontend (default)

.EXAMPLE
    .\scripts\deploy.ps1 -Target backend

.EXAMPLE
    .\scripts\deploy.ps1 -Target both
#>
[CmdletBinding()]
param(
    [ValidateSet('frontend', 'backend', 'both', 'plugin')]
    [string]$Target = 'frontend',

    # Connection settings are intentionally NOT hardcoded here (this file is in
    # source control). They are resolved below from, in priority order: an
    # explicit -param, an environment variable, or the gitignored
    # scripts/deploy.config.ps1. See scripts/deploy.config.example.ps1.
    [string]$VpsHost,

    [string]$VpsUser,

    [string]$KeyPath,

    [string]$WebRoot,

    [switch]$SkipBuild,

    [switch]$NoBackup
)

$ErrorActionPreference = 'Stop'
# PowerShell 7.4+ makes a non-zero *native* exit throw under ErrorActionPreference
# 'Stop', which would bypass our own $LASTEXITCODE checks and can exit silently if
# the window closes. Turn it off so our checks/messages always run. (No-op on 5.1.)
$PSNativeCommandUseErrorActionPreference = $false

function Write-Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Write-Ok($msg) { Write-Host "    $msg" -ForegroundColor Green }
function Fail($msg) { Write-Host "`nERROR: $msg" -ForegroundColor Red; exit 1 }

# Catch-all: show any unexpected terminating error instead of exiting silently.
trap { Write-Host "`nERROR: $($_.Exception.Message)" -ForegroundColor Red; exit 1 }

# Run a remote command via plink. Retry once (after the 30s ufw window) ONLY when
# the failure looks like a dropped/refused connection — not a genuine command
# error (so a real failure isn't misreported as a rate-limit and re-run).
function Invoke-RemoteWithRetry([string]$cmd) {
    $out = & $plink -batch -i $KeyPath $remoteTarget $cmd 2>&1
    foreach ($line in $out) { if ("$line".Trim()) { Write-Host "    $line" } }
    if ($LASTEXITCODE -eq 0) { return $true }
    $text = $out | Out-String
    if ($text -notmatch 'Network error|Connection (timed out|refused|reset|abandoned)|Unable to (open connection|authenticate)|Server unexpectedly closed') {
        return $false   # genuine command failure - do not retry
    }
    Write-Host "    Connection failed - likely the SSH rate limit (ufw limit ssh). Waiting 35s, then retrying once..." -ForegroundColor Yellow
    Start-Sleep -Seconds 35
    & $plink -batch -i $KeyPath $remoteTarget $cmd
    return ($LASTEXITCODE -eq 0)
}

# One pscp upload of the bundles (bare file names in $BundleDir: run from there so
# no path with a space reaches the argument list) to the SSH user's home directory,
# with an elapsed-time indicator and no polling. Returns @{ Ok; Err; Elapsed }.
function Invoke-Upload([string[]]$Files) {
    $outFile = Join-Path $env:TEMP ("pscp-out-{0}.log" -f [guid]::NewGuid())
    $errFile = Join-Path $env:TEMP ("pscp-err-{0}.log" -f [guid]::NewGuid())
    $proc = Start-Process -FilePath $pscp -NoNewWindow -PassThru -WorkingDirectory $BundleDir `
        -RedirectStandardOutput $outFile -RedirectStandardError $errFile `
        -ArgumentList (@('-batch', '-q', '-i', $KeyPath) + $Files + "${remoteTarget}:")
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $maxSeconds = 900
    $killed = $false
    while (-not $proc.HasExited) {
        Start-Sleep -Seconds 1
        Write-Progress -Activity 'Uploading' -Status ("{0} - {1:mm\:ss} elapsed" -f ($Files -join ', '), $sw.Elapsed)
        if ($sw.Elapsed.TotalSeconds -gt $maxSeconds) { try { $proc.Kill() } catch {}; $killed = $true; break }
    }
    $proc.WaitForExit()
    $sw.Stop()
    Write-Progress -Activity 'Uploading' -Completed
    $code = $proc.ExitCode
    $err = if ($killed) { "Upload exceeded ${maxSeconds}s and was aborted." }
    elseif ($code -ne 0) { Get-Content $errFile -Raw -ErrorAction SilentlyContinue } else { '' }
    Remove-Item $outFile, $errFile -Force -ErrorAction SilentlyContinue
    return [pscustomobject]@{ Ok = ($code -eq 0); Err = $err; Elapsed = $sw.Elapsed }
}

# ── Resolve connection settings (param > env var > gitignored local config) ───
# scripts/deploy.config.ps1 (gitignored) may set $DeployVpsHost / $DeployVpsUser /
# $DeployKeyPath / $DeployWebRoot. Copy scripts/deploy.config.example.ps1 to set
# yours. Keeping the host, key path, and web root out of the tracked script means
# no infrastructure details live in git history.
$localConfig = Join-Path $PSScriptRoot 'deploy.config.ps1'
if (Test-Path $localConfig) { . $localConfig }

if (-not $VpsHost) { $VpsHost = if ($env:DEPLOY_VPS_HOST) { $env:DEPLOY_VPS_HOST } else { $DeployVpsHost } }
if (-not $VpsUser) { $VpsUser = if ($env:DEPLOY_VPS_USER) { $env:DEPLOY_VPS_USER } elseif ($DeployVpsUser) { $DeployVpsUser } else { 'root' } }
if (-not $KeyPath) { $KeyPath = if ($env:DEPLOY_KEY_PATH) { $env:DEPLOY_KEY_PATH } else { $DeployKeyPath } }
if (-not $WebRoot) { $WebRoot = if ($env:DEPLOY_WEB_ROOT) { $env:DEPLOY_WEB_ROOT } else { $DeployWebRoot } }

if (-not $VpsHost) { Fail "No deploy host configured. Pass -VpsHost, set `$env:DEPLOY_VPS_HOST, or create scripts/deploy.config.ps1 (copy scripts/deploy.config.example.ps1)." }
if (-not $KeyPath) { Fail "No SSH key path configured. Pass -KeyPath, set `$env:DEPLOY_KEY_PATH, or set `$DeployKeyPath in scripts/deploy.config.ps1." }
if (-not $WebRoot) { Fail "No web root configured. Pass -WebRoot, set `$env:DEPLOY_WEB_ROOT, or set `$DeployWebRoot in scripts/deploy.config.ps1." }

# ── Resolve paths & tools ─────────────────────────────────────────────────────
$RepoRoot = Split-Path $PSScriptRoot -Parent
$FrontendDir = Join-Path $RepoRoot "frontend"
$BackendDir = Join-Path $RepoRoot "backend"
$DistDir = Join-Path $FrontendDir "dist"

if (-not (Test-Path $KeyPath)) { Fail "SSH key not found: $KeyPath" }

function Resolve-PuttyTool($name) {
    $cmd = Get-Command $name -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $candidate = Join-Path "C:\Program Files\PuTTY" "$name.exe"
    if (Test-Path $candidate) { return $candidate }
    Fail "$name not found. Install PuTTY (winget install PuTTY.PuTTY) or add it to PATH."
}
$pscp = Resolve-PuttyTool "pscp"
$plink = Resolve-PuttyTool "plink"

# Windows' own bsdtar, by full path: Git's GNU tar can come first on PATH, and it
# reads "C:\..." as a remote host.
$tar = Join-Path $env:SystemRoot 'System32\tar.exe'
if (-not (Test-Path $tar)) { Fail "tar.exe not found at $tar (it ships with Windows 10 1803 and later)." }

$remoteTarget = "$VpsUser@$VpsHost"

# ── Bundles ───────────────────────────────────────────────────────────────────
$BundleDir = Join-Path $env:TEMP 'tarotgen-deploy'
Remove-Item $BundleDir -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $BundleDir | Out-Null
$Bundles = [System.Collections.Generic.List[object]]::new()

# Pack $Items (relative to $Root) into tarotgen-<Name>.tgz. $Install is the remote
# shell that puts it live, run from <WebRoot> after every checksum has passed, with
# `unpack <archive> <dir>` available; $Done is printed once the whole run succeeds.
function Add-Bundle([string]$Name, [string]$Root, [string[]]$Items, [string]$Install, [string[]]$Done) {
    $file = "tarotgen-$Name.tgz"
    $path = Join-Path $BundleDir $file
    # ustar, not the default pax: pax carries Windows file flags (SCHILY.fflags)
    # that GNU tar on the droplet warns about for every entry.
    & $tar --format ustar -czf $path -C $Root @Items
    if ($LASTEXITCODE -ne 0) { Fail "Could not pack the $Name bundle (tar exit $LASTEXITCODE). Live site untouched." }
    $count = @(& $tar -tzf $path | Where-Object { $_ -notmatch '/$' }).Count
    $size = (Get-Item $path).Length
    Write-Ok ("Packed {0} files into {1} ({2:N0} KB)." -f $count, $file, ($size / 1KB))
    $Bundles.Add([pscustomobject]@{
            Name    = $Name
            File    = $file
            Size    = $size
            Sha256  = (Get-FileHash $path -Algorithm SHA256).Hash.ToLowerInvariant()
            Install = $Install
            Done    = $Done
        })
}

# ══ Frontend ══════════════════════════════════════════════════════════════════
function Add-FrontendBundle {
    if ($SkipBuild) {
        Write-Step "Skipping frontend build (-SkipBuild); using existing dist/"
    }
    else {
        Write-Step "Building frontend (vite)..."
        Push-Location $FrontendDir
        try {
            npm run build
            if ($LASTEXITCODE -ne 0) { Fail "Frontend build failed (exit $LASTEXITCODE). Live site untouched." }
        }
        finally { Pop-Location }
    }
    if (-not (Test-Path (Join-Path $DistDir "index.html"))) {
        Fail "dist/index.html is missing - nothing to deploy."
    }

    $install = @"
unpack tarotgen-frontend.tgz dist.new
rm -rf dist.old
if [ -d dist ]; then mv dist dist.old; fi
mv dist.new/dist dist
rm -rf dist.new
"@
    if ($NoBackup) { $install += "`nrm -rf dist.old" }
    $install += "`necho 'frontend deployed'"

    $done = @("[OK] Frontend deployed to ${remoteTarget}:$WebRoot/dist")
    if (-not $NoBackup) {
        $done += "Previous build kept at $WebRoot/dist.old (rollback: cd '$WebRoot' && rm -rf dist && mv dist.old dist)."
    }
    $done += "Assets are content-hashed and the PWA auto-updates, so no manual cache bust is needed."

    Add-Bundle -Name 'frontend' -Root $FrontendDir -Items @('dist') -Install $install -Done $done
}

# ══ Backend ═══════════════════════════════════════════════════════════════════
# Runtime PHP payload (everything the live site executes); deliberately excludes
# vendor/ (regenerated on the droplet), and the server-only db/, .env, assets/.
# openapi.json is the committed API spec served at /api/openapi.json and rendered
# by Scalar at /api/docs — it must ship even though swagger-php (which generates
# it) is a dev-only dependency not installed in production. scripts/ ships because
# cron runs scripts/retention.php (AUDIT §1.7); .htaccess keeps it off the web.
$BackendPayload = @('api', 'includes', 'scripts', 'og.php', 'openapi.json', 'composer.json', 'composer.lock', '.htaccess')

function Add-BackendBundle {
    Write-Step "Packing backend payload..."
    foreach ($item in $BackendPayload) {
        if (-not (Test-Path (Join-Path $BackendDir $item))) { Fail "Backend payload item not found: $(Join-Path $BackendDir $item)" }
    }

    $items = $BackendPayload -join ' '
    $install = @"
rm -rf .deploy.new
unpack tarotgen-backend.tgz .deploy.new/payload
stage='.deploy.new/payload'
for p in $items; do
  if [ ! -e "`$stage/`$p" ]; then echo "missing staged `$p" >&2; exit 3; fi
done
# Did production dependencies change since the live deploy?
if cmp -s "`$stage/composer.lock" composer.lock 2>/dev/null; then deps_changed=0; else deps_changed=1; fi
# Single rollback archive of the current backend.
mkdir -p .deploy
tar czf '.deploy/backend-prev.tgz' $items 2>/dev/null || true
# Swap each path into place.
for p in $items; do
  rm -rf "`$p"
  mv "`$stage/`$p" "`$p"
done
rm -rf '.deploy.new'
if [ "`$deps_changed" = "1" ]; then
  echo 'composer.lock changed - installing production dependencies on the droplet...'
  if ! command -v composer >/dev/null 2>&1; then
    echo 'composer not found on PATH - run composer install manually in $WebRoot' >&2; exit 4
  fi
  composer install --no-dev --optimize-autoloader --no-interaction
else
  echo 'composer.lock unchanged - skipping composer install.'
fi
"@
    if ($NoBackup) { $install += "`nrm -f '.deploy/backend-prev.tgz'" }
    $install += "`necho 'backend deployed'"

    $done = @("[OK] Backend deployed to ${remoteTarget}:$WebRoot")
    if (-not $NoBackup) {
        $done += "Previous backend archived at $WebRoot/.deploy/backend-prev.tgz"
        $done += "(rollback: cd '$WebRoot' && tar xzf '.deploy/backend-prev.tgz' && composer install --no-dev -o)."
    }
    $done += "Server-side data (TAROT_DATA_DIR: .env + database) and assets/ were not touched."

    Add-Bundle -Name 'backend' -Root $BackendDir -Items $BackendPayload -Install $install -Done $done
}

# ══ Plugin (Dalamud custom repo) ══════════════════════════════════════════════
# Publishes the FFXIV plugin to <WebRoot>/plugin as a self-hosted "custom repo":
# latest.zip (the built plugin), icon.png, and repo.json (the pluginmaster users
# add under Dalamud's /xlsettings). Independent of the site's dist/ and backend.
function Add-PluginBundle {
    $pluginProj = Join-Path $RepoRoot 'plugin\TarotGen.Plugin\TarotGen.Plugin.csproj'
    if (-not (Test-Path $pluginProj)) { Fail "Plugin project not found: $pluginProj" }

    Write-Step "Building plugin (Release)..."
    # Clean bin/obj first. DalamudPackager's incremental packaging can otherwise
    # leave a STALE latest.zip whose embedded manifest version lags the freshly
    # generated repo.json — Dalamud then offers an update it can never reconcile
    # (the downloaded zip reports the old version). A clean build guarantees the
    # zip's manifest matches the version advertised in repo.json.
    $pluginRoot = Join-Path $RepoRoot 'plugin\TarotGen.Plugin'
    Remove-Item -Recurse -Force (Join-Path $pluginRoot 'bin'), (Join-Path $pluginRoot 'obj') -ErrorAction SilentlyContinue
    & dotnet build $pluginProj -c Release --nologo | Out-Host
    if ($LASTEXITCODE -ne 0) { Fail "Plugin build failed (exit $LASTEXITCODE). Live repo untouched." }

    $outDir = Join-Path $RepoRoot 'plugin\TarotGen.Plugin\bin\Release\TarotGen.Plugin'
    $zip = Join-Path $outDir 'latest.zip'
    $manifestPath = Join-Path $outDir 'TarotGen.Plugin.json'
    $iconPath = Join-Path $RepoRoot 'plugin\TarotGen.Plugin\images\icon.png'
    foreach ($f in @($zip, $manifestPath, $iconPath)) {
        if (-not (Test-Path $f)) { Fail "Expected plugin artifact missing: $f" }
    }

    # Build the repo manifest (pluginmaster): the packaged manifest + download links.
    $base = 'https://tarotgen.io/plugin'
    $m = Get-Content $manifestPath -Raw | ConvertFrom-Json
    # Cache-bust the download URL with the version. Cloudflare caches `latest.zip`
    # (a static extension) keyed by full URL, so after a new upload it keeps serving
    # the STALE cached zip — repo.json then advertises the new version while the
    # downloaded zip reports the old one, and the in-game update can never reconcile.
    # A per-version query string gives each release a distinct cache key (a miss),
    # so Dalamud always pulls the matching zip fresh from the origin.
    $zipUrl = "$base/latest.zip?v=$($m.AssemblyVersion)"
    $m | Add-Member -NotePropertyName DownloadLinkInstall -NotePropertyValue $zipUrl -Force
    $m | Add-Member -NotePropertyName DownloadLinkUpdate  -NotePropertyValue $zipUrl -Force
    $m | Add-Member -NotePropertyName DownloadLinkTesting -NotePropertyValue $zipUrl -Force
    $m | Add-Member -NotePropertyName IconUrl -NotePropertyValue "$base/icon.png" -Force

    Write-Step "Staging plugin repo files..."
    $stageRoot = Join-Path $env:TEMP 'tarot-plugin-deploy'
    $stage = Join-Path $stageRoot 'plugin'
    Remove-Item $stageRoot -Recurse -Force -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force -Path $stage | Out-Null
    (ConvertTo-Json @($m) -Depth 12) | Set-Content -Path (Join-Path $stage 'repo.json') -Encoding UTF8
    Copy-Item $zip -Destination (Join-Path $stage 'latest.zip')
    Copy-Item $iconPath -Destination (Join-Path $stage 'icon.png')
    Write-Ok ("Staged v{0}: repo.json, latest.zip, icon.png" -f $m.AssemblyVersion)

    # Move the live dir ASIDE first and delete it only AFTER the new one is in
    # place, so the window where plugin/ is absent shrinks from "duration of an
    # rm -rf" to the instant between two renames. (During any absence, a request
    # for latest.zip 404s via .htaccess rather than returning the SPA shell — see
    # backend/.htaccess — so Cloudflare can't cache a broken 200-HTML under the
    # .zip URL.)
    $install = @"
unpack tarotgen-plugin.tgz .plugin.new
rm -rf plugin.old
if [ -d plugin ]; then mv plugin plugin.old; fi
mv .plugin.new/plugin plugin
rm -rf .plugin.new plugin.old
echo 'plugin published'
"@
    $done = @(
        "[OK] Plugin published to ${remoteTarget}:$WebRoot/plugin",
        "Custom repo URL to share with testers: $base/repo.json"
    )

    Add-Bundle -Name 'plugin' -Root $stageRoot -Items @('plugin') -Install $install -Done $done
}

# ══ Publish: one upload, one install ══════════════════════════════════════════
function Publish-Bundles {
    $files = @($Bundles | ForEach-Object { $_.File })

    # 1. Upload every bundle (1 connection).
    Write-Step ("Uploading {0} ({1:N0} KB)..." -f ($files -join ' + '), (($Bundles | Measure-Object Size -Sum).Sum / 1KB))
    $r = Invoke-Upload -Files $files
    if (-not $r.Ok) {
        Write-Host "    Upload failed - possibly the SSH rate limit (ufw limit ssh)." -ForegroundColor Yellow
        Write-Host "    Waiting 35s for the 30s window to clear, then retrying once..." -ForegroundColor Yellow
        Start-Sleep -Seconds 35
        $r = Invoke-Upload -Files $files
    }
    if (-not $r.Ok) {
        Fail @"
Upload failed. Live site untouched.
$($r.Err)
  - If the .ppk has a passphrase, load it into Pageant first, then re-run:
        pageant.exe "$KeyPath"
  - First-ever connection from PuTTY also needs the host key cached once:
        plink -i "$KeyPath" $remoteTarget   (accept the fingerprint)
  - Otherwise verify the host ($VpsHost) and user ($VpsUser).
"@
    }
    Write-Ok ("Upload finished in {0:mm\:ss}." -f $r.Elapsed)

    # 2. Verify every checksum, then install each bundle in order (1 connection).
    # A failed checksum stops the run before anything is touched; `set -e` stops it
    # at the first failing step after that.
    Write-Step "Verifying, unpacking and swapping into place..."
    $sums = ($Bundles | ForEach-Object { "'$($_.Sha256)' '$($_.File)'" }) -join ' '
    $remote = @"
set -e
test -d '$WebRoot'
cd "`$HOME"
printf '%s  %s\n' $sums | sha256sum -c --quiet -
cd '$WebRoot'
# unpack <archive in `$HOME> <dir>: a fresh <dir> holding the archive's files with
# this server's usual owner, modes and mtimes rather than the archive's.
unpack() {
  rm -rf "`$2" && mkdir -p "`$2"
  tar --no-same-owner --no-same-permissions --touch -xzf "`$HOME/`$1" -C "`$2"
  find "`$2" -type d -exec chmod 755 {} +
  find "`$2" -type f -exec chmod 644 {} +
}
"@
    $remote += "`n" + (($Bundles | ForEach-Object { $_.Install }) -join "`n")
    $remote += "`ncd `"`$HOME`" && rm -f $($files -join ' ')"

    if (-not (Invoke-RemoteWithRetry $remote)) {
        Fail @"
Install failed; the lines above show how far it got (each target prints "<target> deployed").
The uploaded bundles are left in the SSH user's home directory. To roll back on the host:
  frontend: cd '$WebRoot' && rm -rf dist && mv dist.old dist
  backend:  cd '$WebRoot' && tar xzf '.deploy/backend-prev.tgz' && composer install --no-dev -o
"@
    }

    foreach ($bundle in $Bundles) {
        Write-Host "`n$($bundle.Done[0])" -ForegroundColor Green
        foreach ($line in $bundle.Done | Select-Object -Skip 1) { Write-Host "     $line" -ForegroundColor DarkGray }
    }
}

# ── Dispatch ──────────────────────────────────────────────────────────────────
Write-Host "Deploy target: $Target -> $remoteTarget : $WebRoot" -ForegroundColor Yellow
switch ($Target) {
    'frontend' { Add-FrontendBundle }
    'backend' { Add-BackendBundle }
    'plugin' { Add-PluginBundle }
    'both' { Add-FrontendBundle; Add-BackendBundle }
}
Publish-Bundles
