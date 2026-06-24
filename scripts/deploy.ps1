#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Build and deploy the TarotGen.io frontend and/or PHP backend to the droplet.

.DESCRIPTION
    The live site at <WebRoot> (Apache + PHP) has a flat layout that mirrors this
    repo's runtime files. This script flattens the repo into that layout WITHOUT
    ever touching the server-side data (db/, .env, assets/decks/).

    -Target frontend (default):
        1. Builds the SPA (vite) -> dist/.
        2. Uploads it to <WebRoot>/dist.new via pscp (one stream; elapsed-time
           indicator, not per-file output).
        3. Verifies every file arrived, then swaps it in: live "dist" -> "dist.old"
           (rollback backup, overwritten each deploy), new build -> "dist".

    -Target backend:
        1. Stages the runtime PHP payload locally (api/, includes/, og.php,
           composer.json, composer.lock, .htaccess) — NOT vendor/, db/, .env, assets/.
        2. Uploads it to <WebRoot>/.deploy.new in one stream.
        3. Archives the current backend to .deploy/backend-prev.tgz (rollback), swaps
           the new files into place, and — only if composer.lock changed — runs
           `composer install --no-dev --optimize-autoloader` on the droplet.

    -Target both: frontend, then backend (with a short pause between to stay under
    the SSH rate limit).

    Uses PuTTY's pscp/plink so the DigitalOcean .ppk key works directly. pscp/plink
    run in batch mode, so a passphrase-protected .ppk must be loaded into Pageant
    first:  pageant.exe "<KeyPath>"  (enter the passphrase once per Windows session).

    Each target uses just THREE SSH connections and does not poll during transfers,
    to stay under `ufw limit ssh` (which drops the IP after 6 connections in 30s).
    A connection that gets rate-limited is retried once after a 35s wait.

.PARAMETER Target
    What to deploy: frontend (default), backend, or both.

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
    [ValidateSet('frontend', 'backend', 'both')]
    [string]$Target = 'frontend',

    [string]$VpsHost = "68.183.138.141",

    [string]$VpsUser = "root",

    [string]$KeyPath = "C:\Users\jwill\OneDrive\Documents\digitalocean-key.ppk",

    [string]$WebRoot = "/var/www/tarotgen.io",

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

# One pscp upload of a local directory -> a remote staging dir, with an elapsed-time
# indicator and no polling. Returns @{ Ok; Err; Elapsed }.
function Invoke-Upload([string]$WorkingDir, [string]$LocalItem, [string]$RemoteDest, [string]$Label, [int]$FileCount) {
    $outFile = Join-Path $env:TEMP ("pscp-out-{0}.log" -f [guid]::NewGuid())
    $errFile = Join-Path $env:TEMP ("pscp-err-{0}.log" -f [guid]::NewGuid())
    $proc = Start-Process -FilePath $pscp -NoNewWindow -PassThru -WorkingDirectory $WorkingDir `
        -RedirectStandardOutput $outFile -RedirectStandardError $errFile `
        -ArgumentList @('-batch', '-C', '-r', '-i', $KeyPath, $LocalItem, "${remoteTarget}:$RemoteDest")
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $maxSeconds = 900
    $killed = $false
    while (-not $proc.HasExited) {
        Start-Sleep -Seconds 1
        Write-Progress -Activity $Label -Status ("{0} files - {1:mm\:ss} elapsed" -f $FileCount, $sw.Elapsed)
        if ($sw.Elapsed.TotalSeconds -gt $maxSeconds) { try { $proc.Kill() } catch {}; $killed = $true; break }
    }
    $proc.WaitForExit()
    $sw.Stop()
    Write-Progress -Activity $Label -Completed
    $code = $proc.ExitCode
    $err = if ($killed) { "Upload exceeded ${maxSeconds}s and was aborted." }
    elseif ($code -ne 0) { Get-Content $errFile -Raw -ErrorAction SilentlyContinue } else { '' }
    Remove-Item $outFile, $errFile -Force -ErrorAction SilentlyContinue
    return [pscustomobject]@{ Ok = ($code -eq 0); Err = $err; Elapsed = $sw.Elapsed }
}

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

$remoteTarget = "$VpsUser@$VpsHost"
$remoteLive = "$WebRoot/dist"
$remoteNew = "$WebRoot/dist.new"
$remoteOld = "$WebRoot/dist.old"

# ══ Frontend ══════════════════════════════════════════════════════════════════
function Deploy-Frontend {
    # 1. Build
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
    Write-Ok "Build present: $DistDir"

    # 2. Connect + prepare staging (1 connection)
    Write-Step "Connecting and preparing staging dir..."
    if (-not (Invoke-RemoteWithRetry "test -d '$WebRoot' && rm -rf '$remoteNew' && mkdir -p '$remoteNew'")) {
        Fail @"
Could not connect, or $WebRoot does not exist / is not writable.
  - If the .ppk has a passphrase, load it into Pageant first, then re-run:
        pageant.exe "$KeyPath"
  - First-ever connection from PuTTY also needs the host key cached once:
        plink -i "$KeyPath" $remoteTarget   (accept the fingerprint)
  - Otherwise verify the host ($VpsHost), user ($VpsUser), and that $WebRoot exists.
"@
    }
    Write-Ok "Connected; staging dir ready."

    # 3. Upload (1 connection)
    $localFileCount = (Get-ChildItem -Recurse -File $DistDir).Count
    if ($localFileCount -eq 0) { Fail "No files in $DistDir to upload." }
    Write-Step ("Uploading {0} files (single stream)..." -f $localFileCount)
    $r = Invoke-Upload -WorkingDir $RepoRoot -LocalItem 'dist' -RemoteDest $remoteNew -Label 'Uploading frontend' -FileCount $localFileCount
    if (-not $r.Ok) {
        Write-Host "    Upload failed - possibly the SSH rate limit (ufw limit ssh)." -ForegroundColor Yellow
        Write-Host "    Waiting 35s for the 30s window to clear, then retrying once..." -ForegroundColor Yellow
        Start-Sleep -Seconds 35
        $r = Invoke-Upload -WorkingDir $FrontendDir -LocalItem 'dist' -RemoteDest $remoteNew -Label 'Uploading frontend' -FileCount $localFileCount
    }
    if (-not $r.Ok) { Fail "Upload failed. Live site untouched (still serving the previous build).`n$($r.Err)" }
    Write-Ok ("Upload finished in {0:mm\:ss}." -f $r.Elapsed)

    # 4. Verify + swap (1 connection)
    Write-Step "Verifying upload and swapping into place..."
    $swap = @"
set -e
cnt=`$(find '$remoteNew/dist' -type f | wc -l)
if [ "`$cnt" -ne $localFileCount ]; then echo "incomplete upload: `$cnt/$localFileCount files" >&2; exit 3; fi
rm -rf '$remoteOld'
if [ -d '$remoteLive' ]; then mv '$remoteLive' '$remoteOld'; fi
mv '$remoteNew/dist' '$remoteLive'
rm -rf '$remoteNew'
"@
    if ($NoBackup) { $swap += "`nrm -rf '$remoteOld'" }
    if (-not (Invoke-RemoteWithRetry $swap)) {
        Fail "Verify/swap failed. The previous build may be at $remoteOld - on the host, restore with: mv '$remoteOld' '$remoteLive'"
    }
    Write-Host "`n[OK] Frontend deployed to ${remoteTarget}:$remoteLive" -ForegroundColor Green
    if (-not $NoBackup) {
        Write-Host "     Previous build kept at $remoteOld (rollback: mv '$remoteOld' '$remoteLive')." -ForegroundColor DarkGray
    }
    Write-Host "     Assets are content-hashed and the PWA auto-updates, so no manual cache bust is needed." -ForegroundColor DarkGray
}

# ══ Backend ═══════════════════════════════════════════════════════════════════
# Runtime PHP payload (everything the live site executes); deliberately excludes
# vendor/ (regenerated on the droplet), and the server-only db/, .env, assets/.
$BackendPayload = @('api', 'includes', 'og.php', 'composer.json', 'composer.lock', '.htaccess')

function Deploy-Backend {
    $remoteStageRoot = "$WebRoot/.deploy.new"
    $remoteStage = "$remoteStageRoot/payload"
    $remoteBackup = "$WebRoot/.deploy/backend-prev.tgz"

    # 1. Stage the payload locally so it uploads as a single stream (1 transfer).
    Write-Step "Staging backend payload..."
    $stageRoot = Join-Path $env:TEMP "tarot-be-deploy"
    $stage = Join-Path $stageRoot "payload"
    Remove-Item $stageRoot -Recurse -Force -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force -Path $stage | Out-Null
    foreach ($item in $BackendPayload) {
        $src = Join-Path $BackendDir $item
        if (-not (Test-Path $src)) { Fail "Backend payload item not found: $src" }
        Copy-Item $src -Destination $stage -Recurse -Force
    }
    $localFileCount = (Get-ChildItem -Recurse -File $stage).Count
    Write-Ok ("Staged {0} files: {1}" -f $localFileCount, ($BackendPayload -join ', '))

    # 2. Connect + prepare staging dir (1 connection)
    Write-Step "Connecting and preparing staging dir..."
    if (-not (Invoke-RemoteWithRetry "test -d '$WebRoot' && rm -rf '$remoteStageRoot' && mkdir -p '$remoteStageRoot'")) {
        Fail @"
Could not connect, or $WebRoot does not exist / is not writable.
  - If the .ppk has a passphrase: pageant.exe "$KeyPath"
  - First connection needs the host key cached: plink -i "$KeyPath" $remoteTarget
"@
    }
    Write-Ok "Connected; staging dir ready."

    # 3. Upload the payload (1 connection)
    Write-Step ("Uploading {0} files (single stream)..." -f $localFileCount)
    $r = Invoke-Upload -WorkingDir $stageRoot -LocalItem 'payload' -RemoteDest $remoteStageRoot -Label 'Uploading backend' -FileCount $localFileCount
    if (-not $r.Ok) {
        Write-Host "    Upload failed - possibly the SSH rate limit. Waiting 35s, then retrying once..." -ForegroundColor Yellow
        Start-Sleep -Seconds 35
        $r = Invoke-Upload -WorkingDir $stageRoot -LocalItem 'payload' -RemoteDest $remoteStageRoot -Label 'Uploading backend' -FileCount $localFileCount
    }
    if (-not $r.Ok) { Fail "Upload failed. Live site untouched.`n$($r.Err)" }
    Write-Ok ("Upload finished in {0:mm\:ss}." -f $r.Elapsed)

    # 4. Verify + archive + swap + (conditional) composer install (1 connection)
    Write-Step "Verifying, backing up, and swapping into place..."
    $items = $BackendPayload -join ' '
    $swap = @"
set -e
cd '$WebRoot'
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
echo 'backend-deployed'
"@
    if ($NoBackup) { $swap += "`nrm -f '$remoteBackup'" }
    if (-not (Invoke-RemoteWithRetry $swap)) {
        Fail @"
Verify/swap/install failed. If the swap happened, the previous backend is archived at
  $remoteBackup
Restore on the host with:
  cd '$WebRoot' && tar xzf '.deploy/backend-prev.tgz' && composer install --no-dev -o
"@
    }
    Write-Host "`n[OK] Backend deployed to ${remoteTarget}:$WebRoot" -ForegroundColor Green
    if (-not $NoBackup) {
        Write-Host "     Previous backend archived at $remoteBackup" -ForegroundColor DarkGray
        Write-Host "     (rollback: cd '$WebRoot' && tar xzf '.deploy/backend-prev.tgz' && composer install --no-dev -o)." -ForegroundColor DarkGray
    }
    Write-Host "     Server-side db/, .env, and assets/ were not touched." -ForegroundColor DarkGray
}

# ── Dispatch ──────────────────────────────────────────────────────────────────
Write-Host "Deploy target: $Target -> $remoteTarget : $WebRoot" -ForegroundColor Yellow
switch ($Target) {
    'frontend' { Deploy-Frontend }
    'backend' { Deploy-Backend }
    'both' {
        Deploy-Frontend
        Write-Step "Pausing 35s between targets to stay under the SSH connection rate limit..."
        Start-Sleep -Seconds 35
        Deploy-Backend
    }
}
