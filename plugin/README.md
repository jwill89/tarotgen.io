# TarotGen.io — FFXIV Dalamud Plugin

A [Dalamud](https://github.com/goatcorp/Dalamud) plugin for **Final Fantasy XIV** that talks to the
[TarotGen.io](https://tarotgen.io) REST API: draw a tarot reading in-game (pick a public spread or a
free draw), view any reading by its share code, optionally link a TarotGen account to lock and track
readings, push a reading to another plugin user with a chatless in-game prompt, and report a card scan
that needs re-scanning.

It is a **separate, self-contained C# subproject** in the TarotGen repo, built and packaged
independently by [DalamudPackager](https://github.com/goatcorp/DalamudPackager). The site's
`scripts/deploy.ps1` frontend/backend targets do **not** touch it; it has its own version and release
channel (`-Target plugin`).

## What it does (and deliberately does not)

**In scope**

- **Generate a reading** — pick a public spread *or* a free draw (random card count), with reversals. Anonymous.
- **View a reading** by its share code / URL, including password-protected readings (guest enters the password).
- **Optional account** — link a TarotGen account to lock/finalize a reading, sort deck/spread pickers by your favorites, and track generated readings ("My Readings"). Guests never have to link.
- **Send a reading** to another plugin user as a **chatless push** — they get a passive in-game *"‹You› wants to share a Tarot reading"* prompt with **View**/**Dismiss**/**Block** (no chat, no clipboard). Recipients choose a consent tier (party members / friends / party or friends / anyone) or turn receiving off; party covers cross-world and Party-Finder parties, and one install can link several characters.
- **Report a card scan issue** from the card lightbox, so scans with artefacts can be flagged for re-scanning.

**Out of scope** (lives on the website): admin, account/profile management, changelog viewing, PNG
export, echoing readings to game chat, the drag/rotate placement editor, custom spreads, drawing
additional cards into a reading, custom readings from hand-picked cards, and deck/spread submission.

## Build target

| | |
|---|---|
| Dalamud | **API 15** (Dalamud 15.x, FFXIV Patch 7.5) |
| Runtime | **.NET 10** — TFM `net10.0-windows`, `<Project Sdk="Dalamud.NET.Sdk/15.0.0">` |
| Language | C# |
| Platform | Windows (Dalamud is Windows-first; Wine/Linux via XIVLauncher is handled with fallbacks) |
| License | Open source (required for the official Dalamud repo; the site backend is otherwise proprietary) |

## Building

Requires the Dalamud dev SDK on disk (XIVLauncher installs it to `%AppData%\XIVLauncher\addon\Hooks\dev`)
plus the .NET 10 SDK. Then:

```powershell
dotnet build plugin\TarotGen.Plugin\TarotGen.Plugin.csproj -c Debug     # compile
dotnet build plugin\TarotGen.Plugin\TarotGen.Plugin.csproj -c Release   # + DalamudPackager -> bin\Release\TarotGen.Plugin\latest.zip
```

Or open `TarotGen.Plugin.sln` in Visual Studio 2026 / Rider 2025.3. To try it in-game: `/xldev` →
Dev Plugin Locations → point at the built DLL, then `/tarot`. The plugin **version** comes from
`<Version>` in the `.csproj` — bump it to make Dalamud offer an update to installed testers.

## Installing (testers)

The plugin is published to a self-hosted **custom Dalamud repo**. In-game:

1. `/xlsettings` → **Experimental** → **Custom Plugin Repositories**.
2. Add `https://tarotgen.io/plugin/repo.json`, then **Save**.
3. `/xlplugins` → find **TarotGen** → **Install**. Run `/tarot`.

## Publishing

From the repo root (needs the deploy settings in `scripts/deploy.local.ps1`):

```powershell
.\scripts\deploy.ps1 -Target plugin
```

This builds Release and uploads `latest.zip`, `icon.png`, and a generated `repo.json` (the
pluginmaster) to `https://tarotgen.io/plugin/`.

## How it authenticates

The plugin is a native `HttpClient`, so it uses **Bearer tokens** rather than the site's session
cookie. Linking or connecting opens the site's `/authorize` page in the browser and hands a token back
to a local loopback listener (OAuth-style **loopback + PKCE**, no copy-paste):

- an **account token** for the read-only account routes it may reach (locking, favorites, My Readings), and
- a per-install **client token** for the chatless share relay.

Both are **DPAPI-encrypted** at rest (plaintext fallback under Wine) and are individually revocable —
account tokens from the site's *Connected Apps* screen. The relay never stores a plaintext roster of
players (recipient `Character@World` is kept only as a keyed hash), and consent tiers + a block list +
rate limits guard against unwanted shares.

## Backend it talks to

PHP **8.5 / Slim 4** on one DigitalOcean droplet — **Apache + `mod_php` (prefork MPM)**, SQLite (WAL),
behind **Cloudflare**. The endpoints it uses are the `Plugin`- and `Cards`-tagged routes in the API
(`/plugin/*`, `/account/tokens`, `/card-reports`); browse the live reference at
[`/api/docs`](https://tarotgen.io/api/docs).
