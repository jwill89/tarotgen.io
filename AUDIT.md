# TarotGen.io — Codebase Audit (2026-10-27)

An antagonistic pass over the whole repo (frontend, backend, plugin, DB, tooling,
tests, product gaps). Written as a work list for an agent or a person to burn
down. Every finding was verified against the tree on the date above unless
marked **unverified**; a few earlier suspicions that did *not* survive
verification are listed at the end so nobody re-chases them.

## How to use this file

- Work top to bottom within a section; sections are ordered by risk. §10 has a
  suggested sequencing across sections.
- Tags: **[critical] [high] [medium] [low]** = impact. **[S] [M] [L]** = effort
  (hours / a day / multiple days).
- Tick the box when the **Verify** step passes, not when the edit lands.
- Conventions, commands, and layering rules live in `AGENTS.md`; read it first.
  Three rules that bite here:
  1. `backend/db/schema.sql` must be edited **by hand** after a migration. Do not
     regenerate it with `scripts/dump_schema.php` — its `ORDER BY name` reorders
     the whole file, and a dev DB that has drifted (as it had until §1.2/§2.1,
     after which it matched `schema.sql` object-for-object) silently rewrites
     unrelated tables.
  2. `backend/db/migrate_*.php` scripts are **gitignored**; `scripts/deploy.ps1`
     never uploads `db/`. Every migration must be copied to the droplet's
     `<WebRoot>/db/` and run there *before* deploying backend code that needs it.
  3. After route/schema changes: `composer docs` → commit `backend/openapi.json`
     → `npm run gen:types`. `OpenApiSpecTest` and CI both fail on drift.
- Product constraint: **this site does not interpret readings.** No card
  meanings, no AI readings, no "what does this spread say". It is a tool for
  people who do readings. Do not propose or build that.

## Status (2026-10-28, end of day)

**32 items done, 36 open — 12 of the open ones are §9 product features.**

| § | Area | Done | Open | What's left |
|---|---|---|---|---|
| 0 | State of the tree | 1 | 2 | Owner's git housekeeping: dead worktrees/branches, old branches, a stash |
| 1 | Bugs and risks | 8 | 1 | §1.7 retention — built, cron installed; tick after the first live run (03:17 UTC 2026-10-29) |
| 2 | Database | 7 | 0 | Prod: run §2.3's and §2.7's migrations (backup first) before the next backend deploy |
| 3 | Performance | 5 | 0 | — |
| 4 | Duplication | 7 | 0 | — |
| 5 | Standards | 4 | 5 | §5.1 `"*"` constraints, §5.3 metadata, §5.4 plugin/AGENTS doc lines, §5.6 a11y, §5.9 Slim error shape |
| 6 | Tooling | 0 | 5 | Stylelint, Knip, coverage, PHPStan extensions, plugin CI, Dependabot (a few sub-items already done — see each) |
| 7 | Dependencies | 1 | 3 | In-range updates, the swagger-php 7 / vitest 5 / happy-dom 21 majors, plugin packages; TypeScript 7 waits for Vue |
| 8 | Tests | 2 | 5 | More HTTP tests, Data classes, 7 composables, component tests, Playwright, coverage |
| 9 | Product | 0 | 12 | All |

**Edited but not yet committed/deployed** (as of this writing): the reading-
endpoint API docs + `ReadingPayloadsTest` (§4.4 follow-up), the real
`type-check` with its 14 type and 29 lint fixes (§4.4), and removing the two
Bulma leftovers from the home cards (§3.3). Needs a frontend and a backend deploy.

## Decisions and production facts (2026-10-28)

**Working agreement (owner's decisions):**

- **Agents edit and verify; the owner commits.** No commits, branches, pushes,
  or pruning by an agent. §0's git housekeeping is the owner's.
- **Droplet access** goes through the deploy SSH setup (`scripts/deploy.config.ps1`
  → plink/pscp via Pageant). Read-only checks run freely; **every prod-changing
  command (migration, cron, upload) is proposed and approved first.** Batch
  commands: the droplet runs `ufw limit ssh` (6 connections / 30 s).
- **Backups (§1.1): local rotation on the droplet, 14 copies.** No off-site copy.
- **Guest-reading retention (§1.7): 1 year.**
- **Exposure notification (§1.0): none** — too few users to warrant one.
- **CSP (§1.5): keep Cloudflare Web Analytics and Bot Fight Mode**; allow them
  with a per-response nonce.

**Read-only reconnaissance of production** (2026-10-28; these supersede the
audit's assumptions where they differ):

| Fact | Value | Affects |
|---|---|---|
| PHP / SAPI | 8.5.12, mod_php; `date.timezone = UTC`; OS zone `Etc/UTC` | §1.4 is latent in prod |
| Web `php.ini` | `error_reporting = E_ALL & ~E_DEPRECATED`, `display_errors = Off`, `log_errors = On` → `/var/log/apache2/error.log` (daily, 14 rotations) | §1.2, §1.3 |
| SQLite | 3.45.1 (PDO); WAL; DB **1.43 MB**; `sqlite3`, `gzip`, `rclone` installed; 21 GB free | §1.1, §2.3 |
| Cron | none for `root` or `www-data`; `/etc/cron.d`: certbot, e2scrub_all, php, sysstat | §1.1, §1.7 |
| `decks` columns | **no legacy columns** (`is_thoth`/`has_extras`/`non_standard`/`total_cards` already dropped) | §1.2 |
| Schema drift vs `schema.sql` | identical except (a) `decks` — **`schema.sql` is stale**, still lists the legacy columns; (b) `card_report_submissions` + `card_reports.round` — the pending migration | §1.2, §0 |
| Pending migration | `card_report_submissions` **absent** on prod | §0 |
| Readings | 2,044 total, 1,925 guest; **210 guest readings older than 1 year** (oldest 2025-03-16) | §1.7, §2.3 |
| Relay tables | `plugin_clients` 7 rows, `plugin_client_identities` 7, `plugin_messages` 0, `plugin_blocks` 0 | §2.1 |
| Error log, 14 days | 0 dynamic-property lines (deprecations aren't logged); 0 `session_set_cookie_params` lines | §1.2, §1.3 |
| Card image | `Card_0001.png` = **5.1 MB**, no `Cache-Control`, `cf-cache-status: MISS`; its WebP thumb is 62 KB | §3.1, §3.2 |
| Ad-hoc DB copies on server | four `tarotdb.pre-*.db` from July migrations + `db/old/` | §1.1 |

*That table is the morning-of snapshot that shaped the plan. Since changed by the
work below: cron now runs the nightly backup (§1.1) and retention (§1.7); the
`.env` and DB moved out of the web root (§1.0); `schema.sql` matches prod and
the pending migration ran (§0, §1.2); the relay tables are gone (§2.1); card
images are cached for 30 days at the edge with a full-size WebP beside each PNG
(§3.1, §3.2).*

## 0. State of the tree (read before doing anything)

- [x] **Uncommitted feature work is sitting in the tree.** Card-report reasons
      (22 modified + 7 new files: `CardReportReason.php`, `CardReportSubmission.php`,
      `CardReportDialog.vue`, `useCardReports.ts`, `utils/cardReports.ts`, two specs).
      All gates were green when it was written. Commit it (or stash it) before
      starting audit work so diffs stay reviewable. It needs
      `php db/migrate_card_report_submissions.php` run on the droplet **before**
      the next backend deploy.
  - **Done 2026-10-28:** committed by the owner; the migration ran on the
    droplet (prod, dev and `schema.sql` matched object-for-object afterwards).
- [ ] **Three dead worktrees + branches.** `.claude/worktrees/{dazzling-lovelace-4f59b9,
      pensive-tereshkova-d135ec,priceless-jackson-d01aeb}` and branches
      `claude/dazzling-lovelace-4f59b9`, `claude/pensive-tereshkova-d135ec`,
      `claude/priceless-jackson-d01aeb` have **zero commits** ahead of `main`.
      They were spun off for the session-cookie warning, the stale relay docs,
      and the dark `color-scheme`; none of that work landed, so all three items
      are still open below (§1.3, §5.4, §5.8). `git worktree remove` + `git branch -D`.
  - **Still present (2026-10-28, end of day)**, but nothing of value is in them
    any more: §1.3 and §5.8 were done on `main`, and §5.4 mostly. Pure clutter —
    the owner's to remove.
- [ ] Review the other local branches (`api-rewrite`, `dalamud-plugin`) with
      `git branch --merged main` and the stash `stash@{0}` ("pre-reskin tweaks");
      drop what is dead.

### Known-good — leave alone

So the next pass doesn't churn things that are right: CSPRNG Fisher–Yates draw
and cut-and-turn reversals (`ReadingService`); hashed session ids and
write-throttled session rows (`DbSessionHandler`); hashed user/plugin tokens
with PKCE (`PluginTokenService`); Cloudflare-range-gated `ClientIp`;
`OriginGuard`; `password_hash` never hydrated onto `Reading`/`User`; lazy route
chunks and the three vendor groups in `vite.config.ts`; the spec-drift and
generated-types CI checks; forgot-password non-enumeration; PHPStan 8 with a
five-entry baseline; strict TS + typed ESLint. *(Correction 2026-10-28: strict
TS was configured but never actually checked — `npm run type-check` checked no
files, and a broken `vue` augmentation blinded typed ESLint too. Both fixed; see
§4.4. They are known-good from that fix on.)*

---

## 1. Bugs and risks

- [x] **1.0 The production database and `.env` were publicly downloadable.** [critical] [S + M]
  - **Found 2026-10-28** while placing the §1.1 backup script. The deploy flattens
    `backend/` into one web root and `.htaccess` serves every existing file, so
    `/.env`, `/db/tarotdb.db` (+ the `db/tarotdb.pre-*.db` copies),
    `/.deploy/backend-prev.tgz`, `/composer.*`, `/openapi.json` were served
    verbatim, and any `.php` under `db/`, `scripts/` (incl. `make_admin.php`),
    `includes/`, `vendor/`, `api/` executed by URL.
  - **Evidence:** the Apache access log (14 days, Oct 14-28) shows `GET /.env`
    answered with the real file (3,177 bytes logged = 2,345-byte body + headers)
    **11 times between Oct 17 and Oct 26**, by scanners. No download of the DB
    appears in those 14 days; earlier history is gone.
  - **Hotfix — DONE 2026-10-28 05:06 UTC:** deny rules at the top of
    `backend/.htaccess` and an index.php-only rule in `backend/api/.htaccess`;
    installed on the droplet with automatic rollback; 23 server-side checks plus
    external bypass attempts (encoded, doubled-slash, dot-segment paths) all 404.
    Previous files kept as `.htaccess.bak-20261028-050600` (Apache denies `.ht*`).
  - **Owner (in progress):** rotate `GOOGLE_CLIENT_SECRET` and
    `CLOUDFLARE_TURNSTILE_SECRET`; decide on notifying the 7 users and
    contact-form senders (DB held emails, Argon2id hashes, contact messages,
    reading contents; sessions/tokens are stored hashed and are not replayable).
    `PLUGIN_IDENTITY_SALT` is dead (§2.1) — delete, don't rotate.
    *2026-10-28:* both secrets rotated and the salt line deleted by the owner.
    **Decided (owner): no user notification** — the user base is too small to
    warrant one.
  - **Durable fix — DONE 2026-10-28 05:21 UTC** (commit `2a44c27` + droplet
    change): `Tarot\Config\Paths` resolves `.env` and the DB from
    `TAROT_DATA_DIR` (fallback `backend/` for dev); `Connection` refuses to
    create a missing DB. On the droplet: `/var/lib/tarotgen/{.env, db/tarotdb.db}`
    (root:www-data 640 / www-data 640, dirs 750); `SetEnv TAROT_DATA_DIR` in both
    tarotgen.io vhosts; `TAROT_DATA_DIR` in `/etc/environment`. Old files archived
    (root-only) in `/var/backups/tarotgen/web-root-data-20261028-052131/`; the web
    root's `db/` now holds only migration scripts. Verified: real reading served
    from the new copy after the old one was gone; Apache session writes land in
    it; a fresh SSH session's PHP CLI resolves and opens it.
  - **Follow-ups:** rotated secrets go in **`/var/lib/tarotgen/.env`** (read per
    request — no reload needed). Delete the archive
    `web-root-data-20261028-052027/` (a rolled-back first attempt) once §1.1
    backups exist. Long term, give the vhost a `public/` document root so code,
    vendor and scripts aren't in it either.

- [x] **1.1 No database backups exist anywhere.** [critical] [S]
  - **Where:** one SQLite file, one droplet. `scripts/deploy.ps1` deliberately
    never touches `db/`; nothing else does either. No cron, no script, no doc
    (confirmed: no crontab for `root`/`www-data`). The only copies are four
    ad-hoc `tarotdb.pre-*.db` files from July migrations.
  - **Fix (decided: local rotation, 14 copies):** a script (shell or
    `backend/scripts/backup_db.php`) that runs
    `sqlite3 db/tarotdb.db ".backup '<dir>/tarotdb-$(date +%F).db'"`
    (online-safe under WAL), `PRAGMA integrity_check` on the copy, gzips, and
    keeps the newest 14. Put the backup directory **outside the web root** (e.g.
    `/var/backups/tarotgen/`) so a backup can never be served over HTTP. Nightly
    entry in `/etc/cron.d/tarotgen`. Document in `AGENTS.md` §Deployment. Once
    it runs, the ad-hoc `pre-*.db` copies can go. Note this protects against bad
    migrations and corruption, **not** droplet loss — revisit off-site later.
  - **Verify:** restore a backup into a temp file, `PRAGMA integrity_check` = ok,
    `SELECT COUNT(*) FROM readings` matches prod within the day. Do the restore
    drill once, for real.
  - **DONE 2026-10-28 05:27 UTC.** `scripts/server/tarotgen-backup.sh` →
    `/usr/local/sbin/tarotgen-backup`; `scripts/server/tarotgen.cron` →
    `/etc/cron.d/tarotgen` (03:17 UTC nightly). Each run: `.backup` as www-data,
    switched to a self-contained rollback-journal file, `integrity_check`,
    gzipped root-only (600) into `/var/backups/tarotgen/nightly/`, newest 14 kept
    (~199 KB each); logs to `journalctl -t tarotgen-backup`. First run + restore
    drill passed: gunzipped copy integrity ok and **all 24 tables matched the live
    DB row-for-row** (2,044 readings). Still open: off-site copy (droplet loss),
    and alerting if a nightly run fails (today a failure is only visible in the
    journal).

- [x] **1.2 Dev deck loads emit dynamic-property deprecations; `schema.sql` is stale.** [low] [S]
  - **Correction (prod recon):** prod's `decks` table **does not** have the
    legacy columns, so prod is unaffected. The problem is local: the dev DB and
    the committed `schema.sql` (lines 54-58) still carry `is_thoth`,
    `has_extras`, `non_standard`, `total_cards`, which means anyone rebuilding a
    DB from `schema.sql` gets the bug.
  - **Where:** `backend/includes/Tarot/Data/DeckData.php:10` selects `d.*` into
    `Tarot\Structure\Deck` via `PDO::FETCH_CLASS` (`AbstractData.php:75`); on
    the dev DB `DeckData::retrieve()` prints 108 notices (27 decks × 4 columns).
    Fatal in PHP 9.
  - **Fix:** (a) explicit column list in `DeckData` (the real guard — §2.4);
    (b) run the gitignored `db/drop_legacy_deck_columns.php` on the **dev** DB
    only; (c) replace the `decks` block in `schema.sql` with prod's exact
    `CREATE TABLE "decks"` (no legacy columns).
  - **Verify:** the appendix one-liner prints only `hydrated N decks` on dev; a
    DB built from `schema.sql` has the same `decks` columns as prod.
  - **DONE 2026-10-28:** (a) `DeckData::SELECT_WITH_SYSTEM` lists its columns;
    new `DeckDataTest::testColumnsDeckDoesNotDeclareAreNotHydrated` adds an
    undeclared `is_thoth` column — it fails on the old `d.*` query (deprecation
    + assertion) and passes now. (b) `drop_legacy_deck_columns.php` run on the
    dev DB (backup `db/tarotdb.backup-20261028-011103-pre-1.2.db`): one-liner
    went from the four `Deck::$…` deprecations per deck to a clean
    `hydrated 27 decks`. (c) The four legacy lines removed from the `decks`
    block of `schema.sql`. Prod's `CREATE TABLE "decks"` (read-only query) is
    byte-identical to both the migrated dev DB's and a fresh DB built from
    `schema.sql`. The other `SELECT *` sites remain — §2.4.

- [x] **1.3 PHP warning on every login/logout.** [medium] [S]
  - **Where:** `backend/includes/Tarot/Utility/Session.php:80` —
    `Session::regenerate()` calls `session_set_cookie_params()` while the session
    started by `Session::start()` (line ~58) is active. PHP: *"Session cookie
    parameters cannot be changed when a session is active"*. In dev the warning
    HTML is prepended to the login JSON body. Prod has `display_errors = Off`, so
    responses are clean there; it would be one log line per auth, but the prod
    log shows none in 14 days (7 users, long-lived remember-me sessions — logins
    are rare). `api/index.php:35` already sets the params at bootstrap.
  - **Fix:** in `regenerate()`, drop the `session_set_cookie_params()` call and
    always call `self::sendCookie($lifetime)` after `session_regenerate_id(true)`
    (it already does so for the persistent case), so the cookie attributes are
    set by `setcookie()` which is legal mid-session.
  - **Verify:** `curl -i -X POST localhost/api/auth/login …` returns a body
    starting with `{`; PHP log clean; remember-me cookie still carries the 30-day
    `Expires`.
  - **Correction to the fix:** "always call `sendCookie($lifetime)`" would log
    every non-remembered login straight back out — `$lifetime` is 0 there, and
    `sendCookie()` sends `expires = time() + 0`, an already-expired cookie. The
    failing `session_set_cookie_params()` call was a no-op all along (PHP refuses
    it and keeps the bootstrap params), so the right fix is just to delete it.
  - **DONE 2026-10-28:** `Session::regenerate()` now only calls
    `session_regenerate_id(true)` (+ `sendCookie(30 days)` when persistent), with
    the reasoning in its docblock. Verified on the dev server with a throwaway
    account (DB restored from a pre-test `VACUUM INTO` copy afterwards): login
    with and without remember-me and logout return bodies starting with `{`;
    plain login → session cookie, remember-me → `Max-Age=2592000`, `/me` slides
    it, logout from a remembered session sends exactly one new-ID session cookie
    (PHP drops the old-ID sliding header queued by `start()`), `/me` afterwards
    401; no PHP warning after the change. Same `Set-Cookie` behaviour as before,
    minus the warning. Aside: a remembered password login still sends the
    30-day header twice (`regenerate(persistent)` then `persist()`) — identical
    values, harmless.

- [x] **1.4 Timezone coupling between PHP and SQLite timestamps.** [low] [S]
  - **Prod recon:** prod PHP and OS are both UTC, so this is latent there. It
    bites on dev (Windows PHP writes local time, the UI reads it as UTC) and on
    any future host whose `date.timezone` isn't UTC.
  - **Where:** 18 writes of `date('Y-m-d H:i:s')` (PHP's zone; nothing anywhere
    calls `date_default_timezone_set`) in `Data/PluginTokenData.php` (4),
    `Data/UserTokenData.php` (3), `Data/UserData.php` (3),
    `Data/PluginAuthCodeData.php` (3), `Data/PasskeyData.php` (2),
    `Data/CardReportData.php` (2), `api/Routes/Internal/PasskeyController.php` (1),
    while every `DEFAULT CURRENT_TIMESTAMP` column is UTC and
    `frontend/src/utils/datetime.ts:9` assumes *all* timestamps are UTC. Token
    expiry comparisons, "last reported", and sort orders are correct today only
    because the droplet's PHP zone happens to be UTC.
  - **Fix:** one helper (`Tarot\Utility\Clock::now(): string` returning
    `gmdate('Y-m-d H:i:s')`) and replace all 18; add
    `date_default_timezone_set('UTC')` at the top of `api/index.php`, `og.php`,
    and the CLI scripts as a belt.
  - **Verify:** `grep -rn "date('Y-m-d H:i:s')" backend/includes backend/api`
    returns nothing; run the suite under a non-UTC zone:
    `vendor\bin\phpunit -d date.timezone=America/Chicago`.
  - **DONE 2026-10-28:** the count missed the three **expiry** writes
    (`AuthService` activation/reset, `PluginTokenService::expiry`,
    `date(…, time() + TTL)`), which are compared as strings against `:now` and so
    had to move in step — 21 writes + `ChangelogData`'s date fallback, all now
    `Tarot\Utility\Clock::now()/in()/today()` (`gmdate`). Nothing parses
    timestamps in PHP (no `strtotime`/`DateTime`), so string comparisons stay
    consistent. `api/index.php` pins `date_default_timezone_set('UTC')` as the
    belt; not `og.php` (no date handling) and not the autoloader (that would also
    force UTC on the suite and defeat the non-UTC run). New `ClockTest` runs in
    UTC+14: a user created there had `registered_at` **50,400 s (14 h)** off
    SQLite's clock before the change, ≤2 s after. The non-UTC run also caught a
    test fixture writing an "expired" auth code with `date()` — fixed to
    `Clock::in(-60)`. The grep is empty; 257 tests pass under UTC−11, −6, 0 and
    +14; PHPStan/phpcs clean. Prod data needs nothing (it always ran UTC); rows a
    Windows dev PHP wrote in local time stay as they are on dev.

- [x] **1.5 CSP has been "report-only, promote later" since it shipped.** [medium] [S–M]
  - **Where:** `backend/.htaccess:12`. The policy still allows
    `fonts.googleapis.com`/`fonts.gstatic.com` (fonts are self-hosted now), and
    `/api/docs` (`api/index.php:127`) loads Scalar from `cdn.jsdelivr.net`, which
    the policy would block the day it is enforced (`Header always set` applies
    to PHP responses too).
  - **Fix:** remove the Google Fonts entries; either self-host the Scalar bundle
    or exempt `/api/docs` with a `SetEnvIf Request_URI "^/api/docs"` guard; then
    rename the header to `Content-Security-Policy`. Keep `report-uri`/`report-to`
    if you want ongoing signal.
  - **Verify:** every route + Turnstile on `/login` + `/api/docs` load with a
    clean console under the enforced header.
  - **Partly done 2026-10-28.** Google Fonts hosts removed (fonts self-hosted;
    nothing references them). `/api/docs` now pins Scalar to
    `@scalar/api-reference@1.74.0/dist/browser/standalone.js` with SRI
    (`sha384-VXnK…`, stable across fetches) and sends its **own enforced CSP**
    (one script, `fonts.scalar.com`, `connect-src 'self'` — so no
    `proxy.scalar.com` and no "Ask AI" egress); the site policy skips it via
    `<If "%{THE_REQUEST} !~ …">`. Verified on dev: renders, fonts load,
    "Test Request" GET /config → 200, console clean. The built SPA is clean
    under the site policy (no inline scripts — the one inline `<script>` is
    JSON-LD data — no `style=`, no foreign hosts but Turnstile; Google sign-in
    is a navigation, so `form-action` is moot).
  - **Blocked on an owner decision — what Cloudflare injects.** Browsing prod
    under report-only logged violations from two Cloudflare features, not our
    code: (1) **Bot Fight Mode's JS-detection inline script**, which embeds a
    per-request ray id (hash differs every load — measured), so only a
    per-request **nonce in the CSP header** can allow it (Cloudflare stamps the
    header's nonce onto scripts it injects; `unsafe-inline` defeats the point);
    on the free plan it can't be turned off without turning off Bot Fight Mode;
    (2) the **Web Analytics** beacon from `static.cloudflareinsights.com` — note
    the privacy policy says "we do not use third-party analytics". Options:
    serve the HTML shell through PHP with a per-request nonce (strict policy,
    keeps both features); or turn the features off and enforce the static
    policy as is.
  - **Decided (owner): keep Web Analytics and Bot Fight Mode → nonce.
    Implemented 2026-10-28, awaiting deploy + prod verification.** Every page
    route now goes through `og.php`, which sends an **enforced** CSP built by
    `Tarot\Utility\ContentSecurityPolicy` with a fresh 128-bit nonce per
    response: `script-src 'self' 'nonce-…' challenges.cloudflare.com
    static.cloudflareinsights.com` — no `'unsafe-inline'` (styles keep it).
    The static `.htaccess` header is gone; `/index.html` and `/dist/index.html`
    also route to `og.php` so no CSP-free copy of the app exists. Escape hatch:
    `CSP_MODE=report-only` in the live `.env` (no deploy). Verified locally via
    php-cgi: header present + nonce fresh per response, ordinary pages' body
    byte-identical to the build, `/reading/{id}` share meta intact, look-alike
    paths untouched, the escape hatch flips the header. Unit-tested policy.
    Privacy policy §5/§6 now name Cloudflare Web Analytics accurately — beacon
    reports to our own `/cdn-cgi/rum`; measured on prod: no cookies, no
    storage — and Cloudflare's bot script. **What can only be verified on
    prod:** that Cloudflare stamps the nonce onto its JS-detection script and
    the beacon. After deploy, browse home / New Draw / a reading + lightbox /
    login (Turnstile) / `/api/docs` with the console open: expect zero CSP
    violations and both `/cdn-cgi/challenge-platform/…` and `/cdn-cgi/rum`
    requests. If anything is blocked, set `CSP_MODE=report-only` first, then
    fix.
  - **Verified on prod 2026-10-28** (after the deploy): `/`, `/new`,
    `/reading/{id}`, `/privacy`, `/index.html`, `/dist/index.html` all send an
    **enforced** CSP with a different nonce each, no report-only header,
    `no-cache`; `/reading/{id}` keeps its share meta. Cloudflare **stamps the
    nonce**: in navigation HTML both its JS-detection inline script and the
    analytics beacon carry the response's nonce (`MATCH`), and the detection
    snippet passes it on — `d.nonce=…` on the inner iframe script and
    `a.nonce=…` on the `/cdn-cgi/challenge-platform/…/main.js` loader — so the
    whole chain is allowed. In a fresh browser tab: zero CSP violations on
    home, New Draw, a reading + lightbox (WebP), login (Turnstile renders its
    "Verify you are human" widget; it waits for a human click, as it should for
    an automated browser), beacon loaded and `/cdn-cgi/rum` sent.
  - **One gap found and fixed:** Cloudflare injects the beacon into `/api/docs`
    too, and that page's policy had no nonce, so the beacon was blocked there
    (console error only, no user impact). The docs route now issues its own
    nonce + the beacon host. **Verified on prod 2026-10-28** after the backend
    deploy: fresh nonce per request, both Cloudflare scripts `MATCH`; in a fresh
    tab the reference renders in full, Inter + JetBrains Mono load, the console
    holds only Scalar's version banner, and `/cdn-cgi/rum` and the JS-detection
    `oneshot` both complete.

- [x] **1.6 Reading-id collision is a 500.** [low] [S]
  - **Where:** `backend/includes/Tarot/Service/ReadingService.php:435` — 5 random
    bytes (40 bits), no retry; a UNIQUE violation surfaces as a PDOException.
  - **Fix:** in `persist()`, catch the constraint violation (SQLSTATE 23000),
    regenerate, retry up to 3 times.
  - **Verify:** unit test with a `ReadingRepository` double that throws once.
  - **DONE 2026-10-28:** `persist()` draws a fresh id and retries (3 attempts)
    only when the error is SQLite's `UNIQUE constraint failed:
    readings.reading_id`; any other error, or a third collision, propagates as
    before. Tests replay a *real* SQLite collision exception (captured from an
    in-memory table) — one collision → saved with a new id; three → gives up;
    a foreign-key error → not retried (one attempt).

- [ ] **1.7 Guest readings never expire.** [medium] [S]
  - **Where:** `ReadingData::cleanGuestOlderThan()` (`ReadingData.php:233`) is
    reachable only through the admin "Clean" button (`DELETE /admin/readings/all`).
    Nothing runs it.
  - **Fix (decided: 1 year):** `backend/scripts/retention.php` (uses the same
    Data method with 365 days; also a good place for `PRAGMA optimize`), cron it
    nightly after the backup (§1.1); state the retention window on
    `PrivacyPolicyView.vue`. **The first run deletes 210 of prod's 1,925 guest
    readings** (oldest dates from 2025-03-16) and breaks their share links;
    publish the privacy-page text before the first run.
  - **Verify:** script output count; cron entry on the droplet; privacy page text.
  - **Implemented 2026-10-28 (awaiting deploy + droplet install):**
    `backend/scripts/retention.php` → `ReadingRepository::cleanGuest(365)`, with
    `--dry-run` (same DELETE in a rolled-back transaction) and
    `ANALYZE` after (closes §2.7's ANALYZE note; see the prod dry run below for
    why not `PRAGMA optimize`). Cron: chained
    onto the 03:17 backup with `&&`, so nothing is deleted without a fresh backup;
    output to `journalctl -t tarotgen-retention`. `scripts/` added to the
    backend deploy payload so cron never runs a hand-copied, drifting file (the
    repo's `scripts/` is a superset of what prod had). Privacy policy §8 now states
    the window, the signed-in exemption and the 14-day backup tail; "Last
    updated" bumped. **Account holders are exempt** (owner's decision): the DELETE
    is `user_id IS NULL` only, and every creation path (`ReadingService::persist`
    via `ReadingController::currentUserId`) sets `user_id` for a session *or* a
    linked plugin's bearer token. Readings an account holder makes while signed
    out can't be attributed and count as guest readings — the policy says so.
    New `ReadingDataTest::testCleanGuestOlderThanDeletesOnlyOldGuestReadings`
    (366-day guest goes; 364-day guest and 900-day owned stay). Locally: dry
    run on dev reports 210 and deletes nothing; a real run on a copy deletes
    those 210, leaves all 5 owned, analyses 17 tables; re-run deletes 0.
  - **Order on the droplet:** deploy frontend (policy live) + backend (ships
    the script) → dry run as www-data → install the cron line.
  - **Prod dry run 2026-10-28 06:33 UTC** (after both deploys): 2,044 readings
    (1,925 guest, 119 owned); **210 guest readings** would go (2025-03-16 …
    2025-10-23); no owned reading is older than a year yet. Same 210 under
    cron's bare environment (`env -i` + the cron file's variables), exit 0; the
    count was unchanged afterwards. **Found:** prod's PHP links SQLite 3.45.1,
    where `PRAGMA optimize=0x10002` analysed 1 table (the bit is 3.46+) vs 20 for
    plain `ANALYZE` — tested on a root-only temp copy of the nightly backup,
    deleted after. The script now runs `PRAGMA analysis_limit=400; ANALYZE`;
    that change needs a backend deploy before the cron line goes in.
  - **Cron installed 2026-10-28 06:46 UTC** (after the `ANALYZE` deploy, confirmed
    on the droplet): `/etc/cron.d/tarotgen` root:root 644, LF, trailing newline
    checked; previous file kept as
    `/var/backups/tarotgen/tarotgen.cron.prev-20261028-064618`; cron logged
    `RELOAD (/etc/cron.d/tarotgen)` at 06:47. Rehearsal of the exact installed
    command (`true` for the backup, `--dry-run` added) under cron's environment
    logged `dry run: 210 …` to `journalctl -t tarotgen-retention`; readings
    still 2,044. **First real run: 03:17 UTC 2026-10-29**, after that night's
    backup. Tick this item once `journalctl -t tarotgen-retention` shows
    `deleted 210 …` (± any that age past a year meanwhile) and the backup log
    shows the pre-deletion copy.

- [x] **1.8 Small leaks / decisions to record.** [low] [S]
  - Public `GET /decks` and `/deck-systems` return `submitted_by` user ids
    (`Structure/Deck.php:47`, `Structure/DeckSystem.php:29`). Strip for non-admin
    responses.
  - Registration confirms whether an email / display name exists
    (`Service/AuthService.php:69,72`). Forgot-password correctly does not.
    Acceptable trade-off; write the decision down in `API.md` so it isn't
    "fixed" twice in opposite directions.
  - `frontend/src/composables/useDecks.ts:32` swallows a failed deck load
    (`// Silently fail`), leaving the New Reading page with an empty picker and
    no message. Surface it (toast) — see §4.2, which replaces that fetch anyway.
  - **Found 2026-10-28 (§2.1 verification):** every API 404 writes a full Slim
    stack trace (~2 KB, `[php:notice]`) to the shared `/var/log/apache2/error.log`
    — `addErrorMiddleware(…, true, true)` at `api/index.php:72` logs errors with
    details, and `HttpNotFoundException` counts. The traffic is scanners: in 14
    days ~1,300 API 404s led by `/api/.env` (236), `/api/v1/.env` (162) and
    `..%2f` traversal probes, all correctly 404. Harmless, but it buries real
    errors. Fix: a handler for `HttpNotFoundException` /
    `HttpMethodNotAllowedException` that renders without logging (or log one
    line, no trace); keep full detail for real exceptions.
  - **DONE 2026-10-28 (all four):** `submitted_by` is stripped from public
    `GET /decks`, `/decks/{id}`, `/deck-systems`, `/deck-systems/{id}` via a new
    `AbstractStructure::jsonSerializeWithout()` (admin endpoints keep it; the
    plugin's nullable `SubmittedBy` was never read). The enumeration trade-off
    is written down in `API.md` (forgot-password's uniform reply verified in
    code). Deck-load failures toast (§5.7). 404/405s use the default renderer
    with logging off — same JSON/HTML negotiation, no stack trace; real
    exceptions untouched. (A `static fn` handler broke 404s into 200 + warning:
    Slim binds handler closures to the container — caught on the dev server,
    fixed, documented in a comment.) Also removed the dead share-relay rows from
    `API.md`'s auth section.

---

## 2. Database

- [x] **2.1 Four dead tables and six dead indexes from the removed share relay.** [medium] [S]
  - **Where:** `plugin_clients`, `plugin_client_identities`, `plugin_messages`,
    `plugin_blocks` + `idx_plugin_clients_hash`, `idx_plugin_clients_identity`,
    `idx_client_identities_pair`, `idx_client_identities_hash`,
    `idx_plugin_clients_user`, `idx_plugin_messages_inbox`. Zero references in
    `backend/` or `plugin/` since commit f40037a. Prod holds dead data in two of
    them (`plugin_clients` 7 rows, `plugin_client_identities` 7). The env var
    `PLUGIN_IDENTITY_SALT` is likewise unreferenced but still documented in
    `backend/.env.example`.
  - **Fix:** `db/migrate_drop_plugin_relay.php` with `DROP TABLE IF EXISTS` ×4,
    run dev + prod, hand-edit `schema.sql`, delete the env block. (The relay may
    return over WebSockets; recreate the schema then, with whatever shape that
    design needs — don't keep this one "just in case".)
  - **Verify:** `sqlite3 db/tarotdb.db .tables` shows none of the four;
    `composer test` green; `grep -rn PLUGIN_IDENTITY_SALT backend` empty.
  - **Implemented 2026-10-28 (dev done; prod migration pending):** re-checked —
    no code in `backend/`, `frontend/src`, `plugin/` or `scripts/` references the
    tables, `ClientAuth`, `ShareRelay` or `SharePrompt`.
    `db/migrate_drop_plugin_relay.php` (gitignored like every migration) drops
    children first, all in one transaction, prints each table's row count, and is
    idempotent; `plugin_tokens`/`plugin_auth_codes` (live linking) untouched. Dev:
    dropped 3 tables (dev never had `plugin_client_identities`), integrity ok,
    0 FK violations; second run skips all four. `schema.sql`: a fresh build lost
    exactly the 4 tables + 6 indexes (+ `plugin_blocks`' PK autoindex), the other
    50 objects byte-identical — and the dev DB now equals `schema.sql`
    object-for-object. Also removed: the `PLUGIN_IDENTITY_SALT` block in
    `.env.example` (0 references left) and the unused `clientToken` security
    scheme in `OpenApiSpec.php` (`openapi.json` −5 lines; generated TS unchanged).
    253 tests / PHPStan / phpcs pass. **Prod:** `sudo tarotgen-backup`, upload the
    migration to `<WebRoot>/db/`, run as www-data, verify `.tables`; then delete
    the dead `PLUGIN_IDENTITY_SALT=` line from `/var/lib/tarotgen/.env`.
  - **DONE on prod 2026-10-28 06:56 UTC** (after the backend deploy): backup
    `tarotdb-20261028-065605.db.gz` first; dropped `plugin_messages` (0 rows),
    `plugin_blocks` (0), `plugin_client_identities` (7), `plugin_clients` (7) —
    exactly the dead rows counted above; `plugin_tokens`/`plugin_auth_codes` kept
    (5 rows each). Integrity ok, 0 FK violations, files still www-data. **Prod's
    schema now equals the committed `schema.sql` object-for-object (49)** —
    compared against a fresh build in a root-only temp dir. Live: `/api/config`,
    `/api/decks/1`, a reading → 200, `/api/auth/me` → 401. Owner to delete the
    `PLUGIN_IDENTITY_SALT=` line from the live `.env`.

- [x] **2.2 Legacy deck columns** — handled by §1.2 (drop them, stop selecting `*`).

- [x] **2.3 `readings` has no index on `reading_time`.** [low] [S]
  - **Prod recon:** 2,044 rows in a 1.43 MB DB — the scans cost nothing today.
    Cheap insurance, not a fix for a felt problem.
  - **Where:** used by `StatsService` (totals/daily, lines 68-74, 156-160),
    `ReadingData::listAll` (`ORDER BY r.reading_time DESC … LIMIT/OFFSET`, line 205),
    and `cleanGuestOlderThan` (line 233). The dashboard additionally runs
    `json_extract` over the whole table on every load (`StatsService.php:86-88,
    103, 133`).
  - **Fix:** migration adding `CREATE INDEX idx_readings_time ON readings(reading_time)`
    (consider `(user_id, reading_time)` and dropping `idx_readings_user`). Only
    if the table grows past tens of thousands: generated columns
    `deck_id`/`spread_id` extracted from the JSON, indexed.
  - **Verify:** `EXPLAIN QUERY PLAN` on the three queries shows the index.
  - **Implemented 2026-10-28 (dev done; prod migration pending):**
    `db/migrate_readings_time_indexes.php` (idempotent) adds
    `idx_readings_time (reading_time, reading_id)` and
    `idx_readings_user_time (user_id, reading_time, reading_id)`, drops
    `idx_readings_user` (now a prefix of the second), and runs `ANALYZE readings`.
    The trailing `reading_id` matches every `ORDER BY reading_time DESC,
    reading_id DESC`. Plans on dev: `listByUser`, `countByUser`, `listAll`,
    `cleanGuestOlderThan` and the stats daily series went from a full scan + temp
    B-tree sort to an index scan with no sort. `listAll` was rewritten in §2.5 and
    re-checked: it pages on `idx_readings_time`, then looks up users and decks by
    primary key for the page's rows only. `schema.sql` hand-edited; dev DB =
    `schema.sql` object-for-object. The dashboard's whole-table `json_extract`
    stays as is (generated columns only if the table grows past tens of thousands). `SELECT *` + `FETCH_CLASS` couples schema to Structures.** [medium] [S]
  - **Where:** 18 occurrences across `ChangelogData`, `DeckSystemData` (×6),
    `SpecialCardData` (×3), `UserData` (×4), plus `DeckData`'s `d.*` (fixed in
    §1.2). Any added
    column that a Structure doesn't declare becomes a dynamic property →
    deprecation now, fatal in PHP 9. §1.2 is this bug happening.
  - **Fix:** explicit column lists (a `private const string COLS` per Data
    class, as `ReadingData::SELECT_COLS` already does).
  - **Verify:** `grep -rn "SELECT \*" backend/includes/Tarot/Data` is empty.
    Note the unit tests cannot catch prod schema drift because each test creates
    its own minimal table; the explicit list is the real guard.
  - **DONE 2026-10-28:** the count missed `SpreadData` (×1) and
    `UserSpreadData` (×2); all 18 sites now use a per-class `SELECT_COLS`
    (`SYSTEM_COLS`/`CARD_COLS` in `DeckSystemData`), matching
    `ReadingData::SELECT_COLS`. Every list equals its Structure's properties /
    `hydrate()`'s reads, checked against `schema.sql` (= prod) — so no data
    changes. Side benefit: `UserData`'s rows no longer carry `password_hash`;
    only `findAuthByEmail()`/`getPasswordHash()` select it. The grep is empty.
    New `ProductionSchemaReadsTest` closes the gap noted above: it loads the
    committed `db/schema.sql` into SQLite, seeds one row per table through each
    class's own writers, and runs every read — a mistyped column fails at
    prepare (`no such column`, checked by planting one in `ChangelogData`), a
    stray column into a `FETCH_CLASS` Structure fails as a deprecation. It also
    gives `ChangelogData`, `SpecialCardData` and `SpreadData` their first tests.
    252 tests, PHPStan, phpcs (no new warnings) pass; public deck-system,
    deck-card, spread and changelog endpoints smoke-tested clean on dev.

- [x] **2.5 Two hydration styles.** [low] [M]
  - **Where:** 12 Data classes do `FETCH_ASSOC` + a hand-written `hydrate()`
    (`ChangelogData`, `ContactData`, `FavoriteSpreadData`, `PasskeyData`,
    `PendingSpreadData`, `PluginAuthCodeData`, `PluginTokenData`, `ReadingData`,
    `SpreadData`, `UserData`, `UserSpreadData`, `UserTokenData`); the rest use
    `fetchAllAs`/`fetchOne`.
  - **Fix:** migrate to `fetchAllAs` where the Structure's typed props match
    the columns (the Data classes are in coercive mode, so SQLite's `0/1` → `bool`
    and `"5"` → `int` assignments are fine; keep manual hydration where JSON
    decoding or derived flags are needed, e.g. `ReadingData`).
  - **Verify:** existing Data tests; no new deprecations.
  - **DONE 2026-10-28 — one style, no exceptions (owner's call):** every row
    read now fetches straight into a class through `fetchOne`/`fetchAllAs`, and
    the Data layer has no `FETCH_ASSOC` left (`fetchColumn`/`FETCH_COLUMN` for
    scalars). The "keep manual hydration" cases above didn't hold up:
    - *Derived flags* moved into the SELECT: `User::$google_linked`
      (`COALESCE(google_id, '') <> ''`, so the Google id stays in SQL) and
      `has_passkeys` (`EXISTS …`). The second also ends an N+1: the admin users
      list ran one passkey query per user. `Reading::$password_protected` was
      already computed in SQL; its hydrate was pure casting.
    - *JSON columns:* spread `positions` arrive as text and the property's PHP 8.4
      set hook decodes them (`AbstractStructure::jsonList`), so `Spread`,
      `PendingSpread` and `UserSpread` fetch directly. Checked first that hooks
      fire under `FETCH_CLASS`.
    - *The admin readings list* is a `ReadingSummary` worked out in SQL
      (`json_extract` for deck and spread, a decks join for the name, `Guest` and
      `Deck #<id>` fallbacks), a page at a time. The controller's load-every-deck
      loop is gone.
    - *Arrays that were entities:* the passkey list (`Passkey`), favourite spreads
      (`FavoriteSpread`) and the admin list are now schemas in the spec. Until now
      their endpoints were documented as a bare `object`. The internal lookups
      fetch into small classes with no schema: `PasskeyCredential`,
      `PluginAuthCode`, `UserToken`, and `PluginToken` (which already existed)
      for `findActive`.
    - *The password hash* fetches into `UserCredentials`, where it is `private`
      behind `verify()`/`needsRehash()`, so nothing can read or `json_encode` it.
      This is safer than the array was. It deliberately isn't an
      `AbstractStructure`, whose `jsonSerialize()` reflects private properties too.

    `fetchOne`/`fetchAllAs` use `FETCH_PROPS_LATE` (construct, then fill), so a
    Structure constructor can never overwrite fetched values. The rule, and where
    each kind of conversion belongs, is in `AbstractData`'s docblock and AGENTS
    §4. Every non-nullable property was checked against a NOT NULL column, since
    `FETCH_CLASS` doesn't absorb a NULL the way the old casts did. Removed: seven
    `hydrate()`s, `UserData::userHasPasskeys`, and the unused
    `Reading::setIsFinal`/`setPasswordProtected`.

    Tests: new `ListPayloadsTest` (the three lists against their schemas; the admin
    list's owners, decks, spreads, fallbacks and paging). The spec checker moved
    from `ReadingPayloadsTest` into `HttpTestCase::assertDocumented`, which now
    handles arrays and inline objects; it was confirmed to reject a missing field,
    an object for a list, and an extra nested field. 305 tests, PHPStan and
    php-cs-fixer pass; frontend type-check, lint and 166 tests pass. Against the
    dev DB, read-only: every converted read hydrates every row, and the admin
    list equals the old PHP derivation over all 1,225 readings. Frontend:
    `Passkey`, `FavoriteSpread` and `ReadingSummary` come from the spec, replacing
    three hand-written interfaces. **No prod data step:** deploy backend and
    frontend.

- [x] **2.6 `myReadings` is unbounded.** [medium] [M]
  - **Where:** `AccountController.php:52` → `ReadingData::listByUser` returns
    every reading with its full `reading_info` JSON. `AccountReadingsView.vue`
    has no search, filter, or paging.
  - **Fix:** paginate like `listAll` (`?limit&offset`, total) and return a light
    summary (deck id, spread name, card count, name, date, flags) for the list.
    **Plugin compatibility:** `TarotApiClient.GetMyReadingsAsync` deserialises
    `List<Reading>` and reads `ReadingInfo`; keep `reading_info` in the response
    (or bump the plugin) before changing the shape. Pairs with feature §9.1.
  - **Verify:** payload size for a 200-reading account; plugin "My Readings"
    tab still renders.
  - **DONE 2026-10-28:** `GET /account/readings?limit=&offset=` returns one page
    (max 100) with the total in `X-Total-Count`; **without `limit` the response
    is unchanged** (a plain array), so the current plugin keeps working — page
    it in the plugin later, then the unbounded path can go. `apiRequest`
    success results now carry optional `headers`. My Readings shows 25 per page
    (Reka pagination, styles moved to `style.css` from the admin view's scoped
    copy), reloads after delete, steps back when a delete empties the last
    page, and shows "Couldn't load your readings" + Try again instead of the
    misleading empty state. A test caught `Number(null) === 0` hiding the list
    when the header is missing. Verified on dev with 30 seeded readings
    (pages 25 + 5, delete, step-back). The light-summary payload is left for §9.1.

- [x] **2.7 Cosmetic / when-you-touch-it.** [low]
  - `special_cards` FK has no `ON DELETE CASCADE` (worked around manually in
    `DeckData::delete`, line 125) and its constraint is named
    `FK__sepcial_cards__decks`. SQLite needs a table rebuild to fix; do it when
    the table is next migrated.
    **Implemented 2026-10-28 (dev done; prod migration pending):**
    `db/migrate_special_cards_cascade.php` rebuilds the table (foreign keys off
    outside the transaction → new table → copy → row-count check → drop → rename
    → `foreign_key_check`, which must come back empty or it rolls back and exits 1;
    idempotent). The constraint is now `fk_special_cards_decks … ON DELETE
    CASCADE`. Orphan rollback tested on a copy. With every table that references
    `decks` cascading, `DeckData::delete` is one `DELETE`. New `AdminDecksTest`:
    deleting a deck takes its special cards, reports and favourites and leaves the
    other deck's. It fails (500) against the old schema. **Order on prod: back
    up, run this migration, then deploy the backend** — the new `delete()` relies
    on the cascade.
  - Mixed timestamp sources — §1.4.
  - No `ANALYZE`/`PRAGMA optimize` ever runs; fold into the retention cron (§1.7).
    **Done in §1.7** (`scripts/retention.php` runs `ANALYZE` nightly; `PRAGMA
    optimize` was tried and is ineffective on prod's SQLite 3.45).

---

## 3. Performance and bandwidth

- [x] **3.1 Card images are served with no cache headers.** [high] [S]
  - **Where:** `backend/.htaccess:20-25` sets `Cache-Control` only for `/_app/`
    (hashed bundles) and `/fonts/`. `/assets/decks/**` — the heaviest assets on
    the site, a full-size PNG per lightbox open — fall to Cloudflare's 4-hour
    default and are re-downloaded all day. `share_banner.png` too.
  - **Prod recon:** `curl -sI` on `Card_0001.png` → 5,097,892 bytes, **no
    `Cache-Control` header, `cf-cache-status: MISS`**; `share_banner.png` 604 KB,
    MISS.
  - **Fix:** `SetEnvIf Request_URI "^/assets/decks/" TG_DECK_IMAGE` +
    `Header always set Cache-Control "public, max-age=2592000" env=TG_DECK_IMAGE`
    (30 days; images change only on a re-scan). On re-scan, purge the CF cache
    for that path or bump a `?v=` in `cardImageUrls` (§4.1).
  - **Verify:** `curl -sI https://tarotgen.io/assets/decks/1/Card_0001.png | grep -i cache`.
  - **DONE 2026-10-28** (deploy of `e2189f3` + `.htaccess` follow-up installed
    05:35 UTC with auto-rollback; 27 status/header checks passed). Follow-up
    found while verifying: the existing `/_app/` `immutable` and `/fonts/`
    rules had **never** been sent — those paths are rewritten into `dist/`, and
    `SetEnvIf Request_URI "^/_app/"` doesn't match the rewritten request; they
    now match the served file (`<If "%{REQUEST_FILENAME} =~ m#/dist/_app/#">`).
    The HTML shell (`index.html`, `og.php`) is now `no-cache`, so a returning
    browser can't boot a stale shell naming deleted bundles; missing files under
    `/_app/` and `/fonts/` 404 instead of returning cacheable 200-HTML.
  - **Implemented 2026-10-28:** `/assets/*` 2xx responses get
    `public, max-age=86400, s-maxage=2592000, stale-while-revalidate=604800`
    (Cloudflare 30 days, browsers 1 day; `Header set` without `always`, so
    errors are never edge-cached). Also fixed: a **missing** file under
    `/assets/` fell through to the SPA as 200-HTML, which Cloudflare caches by
    extension (`.png`) — it now 404s like `/plugin/` (matching `dev-router.php`).
    Prod census: all 28 decks complete (2,228 full PNGs, avg 2.2 MB, 5 GB), so
    no live page relied on that fallthrough. **After replacing a card image,
    purge its URL in Cloudflare.**

- [x] **3.2 Lightbox serves raw PNG; only thumbnails are WebP.** [high] [M]
  - **Where:** `ThumbnailService` writes `thumbs/*.webp` only; `imgUrl` in
    `ReadingView.vue:86` points at the PNG. **Prod recon:** a full card is ~5 MB
    of PNG (thumb: 62 KB WebP), so a 10-card spread viewed in the lightbox moves
    ~50 MB — on mobile data, the dominant cost of using the site. Also check
    the export path (`useReadingExport.ts` loads `imgUrl` for the PNG export)
    before changing what `imgUrl` points at.
  - **Fix:** generate a full-size `*.webp` (and optionally AVIF) alongside each
    PNG in `ThumbnailService`; serve it from the lightbox with the PNG as
    fallback (`<picture>` or `onerror` swap). Keep PNGs — the plugin's
    `CardTextureCache` loads them.
  - **Verify:** bytes transferred opening a lightbox before/after.
  - **Implemented 2026-10-28 (awaiting deploy + backfill):** `ThumbnailService`
    → `CardImageService`, which writes a full-size WebP q82 next to each PNG plus
    the 400 px q80 thumb from one decode, regenerates when either is missing or
    older than its PNG (a re-scan refreshes), writes via temp file + rename, and
    reports a `failed` count. Admin routes are now `POST /admin/decks/images` and
    `/admin/decks/{id}/images` (buttons: "Generate web images");
    `scripts/generate_card_images.php` does the backfill without a request time
    limit. Frontend: `imgUrl` is the WebP, `ReadingCard.pngUrl` the fallback —
    the lightbox swaps to the PNG on `@error`, the export retries with it, and
    the report dialog's 52 px preview now uses the thumb instead of the full scan.
    AVIF skipped: WebP already gets ~92% of the win. Measured locally: 5.9 MB PNG → 461 KB WebP (1629×2834, visually
    identical at 1:1) + 72 KB thumb; lightbox load and PNG fallback checked in
    the browser. **Deploy order:** backend → backfill (≈15 min at nice 19,
    ≈1 GB) → frontend. Between the backend and frontend deploys the old admin
    "Generate thumbnails" button 404s; nothing user-facing changes.
  - **DONE 2026-10-28** (commit `37782a9`, deployed): backfill ran 05:54–06:10
    UTC as www-data at nice 19 — 28 decks, 2,228 generated, 0 failed, 952 s;
    every PNG has its display WebP, no temp files left, all www-data-owned,
    dimensions match the masters. 5.25 GB of PNG → 0.35 GB of WebP (6.6%).
    Live: `Card_0001.webp` 377 KB vs the 5.1 MB PNG, `image/webp` with the
    §3.1 cache headers; the lightbox on a real reading loads the WebP at
    1629×2834 for 416 KB with no PNG requests.
  - **Follow-up (plugin):** `CardTextureCache` still downloads the master PNG.
    Switching it to the display WebP needs a check that Dalamud's texture
    provider decodes WebP; until then the PNGs must stay.

- [x] **3.3 The CSS bundle is 407 KB (48.6 KB gz) and roughly 60% unreferenced.** [medium] [M]
  - **Where:** census (appendix): 780 class selectors in `dist/_app/index-*.css`,
    310 referenced anywhere in `src/` (generous substring match), 367 of the
    unused are Bulma `is-*` modifiers, 66 are `fa-*`. `bulma.scss` is already
    modular (57 lines); the remaining weight is helper/modifier generation.
  - **Fix:** trim further in `bulma.scss` (disable the modifier/responsive
    variants you don't use; review the 41 kept helpers in `bulma-helpers.css`),
    rather than a blanket PurgeCSS, which would need a `/^is-/` safelist for the
    classes toggled at runtime and defeat itself. Re-run the census after.
  - **Verify:** census ratio; a visual pass over home, reading, new-draw,
    account settings, two admin pages.
  - **DONE 2026-10-28 — 418 → 256 KB, 48.7 → 34.5 KB gzip (−29%).** Measured
    per module first (each compiled on its own, classes matched against `src/`).
    The weight was not where the census pointed: Bulma's **themes** module was
    181 KB / 17.2 KB gz — the light theme written three times and the dark twice
    (`:root`, `prefers-color-scheme`, `[data-theme]`). The site is dark-only, so
    `bulma.scss` now writes what those rules settle on for
    `<html data-theme="dark">` once — light as the base, dark over it, the
    shared setup — 61 KB / 6.2 KB gz. Dropped `layout/hero` and
    `components/panel` (38 KB): their only "users" were HomeView's own scoped
    `.hero`/`.panel`, which matched Bulma's names by accident. That leak had
    given the home cards a 24 px bottom margin on the first (on a phone it sat
    *inside* the card, not between the two) and a faint white resting shadow on
    the second; compared side by side on the live page, the owner confirmed
    both were artefacts, so they are gone with the modules.
    One unused helper removed (`has-text-weight-bold`). Left alone, on purpose:
    `grid/columns` (28 KB, 334 of 357 classes unused, but 2.9 KB gz — hand-
    trimming the grid would make the next `is-offset-2` silently do nothing)
    and the button colour variants (≈1 KB gz, measured in August).
  - **Verified** with the computed-style walk from the August trim: 35 routes,
    signed in and out, about 7,700 elements × 61 properties, before vs after.
    Identical everywhere except the intended changes listed under §4.6 and the
    home hero's `display` (flex → block, no element changes size).

- [x] **3.4 `og.php` runs for every human visit to `/reading/{id}`.** [low] [M]
  - **Where:** `.htaccess` rewrite; `og.php` does PHP + 2 queries + regex over
    the shell per view and makes those pages edge-uncacheable. Fine at today's
    scale; it is the first thing to revisit if reading views climb. Options:
    rewrite only for crawler user agents, or a 60-second per-id micro-cache.
  - **Since §1.5 (2026-10-28) `og.php` serves every page**, for the per-response
    CSP nonce. Non-reading pages do no queries (file read + header), and the
    shell was already `no-cache`, so no edge caching was lost. The reading-page
    cost above is unchanged; a micro-cache would have to vary the nonce, so
    "crawler-only meta" is now the simpler option.
  - **Decided 2026-10-28: keep it.** Measured on prod (median of 9, as a browser
    navigation): `/privacy` (shell only) 238 ms, `/reading/{id}` 232 ms,
    `/api/config` 245 ms — the share-meta lookup (two primary-key queries)
    doesn't register against the network. A crawler-only rewrite would trade
    that for UA sniffing, where any chat app missing from the list loses its
    link preview without anyone noticing. Revisit only if reading views grow by
    orders of magnitude.

- [x] **3.5 Eager thumbnails.** [low] [S]
  - Only two `loading="lazy"` attributes exist (both `ReadingView.vue`). Add to
    card thumbnails in `AccountReadingsView`, the `CustomReadingView` picker,
    and admin tables.
  - **DONE 2026-10-28.** Every `<img>` reviewed: lazy where an image can start
    off-screen — the card images on the `CustomReadingView` and
    `FreeDrawPlacementView` canvases and the admin card-reports table (one
    thumbnail per row). `AccountReadingsView` no longer shows images. Left eager:
    the brand logo, the lightbox image, the report dialog's preview (visible the
    moment they render) and the reading's card backs (one cached URL).

---

## 4. Duplication

- [x] **4.1 Card image URL building is copied four times.** [medium] [S]
  - **Where:** `'Card_' + String(id).padStart(4, '0')` + `/assets/decks/{deck}/…`
    in `views/ReadingView.vue:83` (+ back-card literals at 66, 71, 81),
    `views/CustomReadingView.vue:200`, `views/FreeDrawPlacementView.vue:134`,
    `views/admin/ErroredCardsView.vue:76`.
  - **Fix:** `src/utils/cardImages.ts` exporting `cardImageUrls(deckId, cardId)`
    → `{ full, thumb }` and `cardBackUrls(deckId)`; the version token from §3.1
    lives here too. Unit-test it.
  - **Verify:** `grep -rn "padStart(4" src --include=*.vue` returns nothing.
  - **DONE 2026-10-28** with §3.2: `src/utils/cardImages.ts` exports
    `cardImageUrls()` → `{ display, png, thumb }` and `cardBackImageUrls()`,
    covered by `cardImages.spec.ts`; all four views use it and the grep is clean.
    No `?v=` token: §3.1 settled on purging Cloudflare after a re-scan instead.

- [x] **4.2 Forty raw `fetch('/api' + …)` call sites bypass the API client.** [medium] [M]
  - **Where:** composables `useAccount` (6), `usePasskeys` (9),
    `useFavoriteDecks` (2), `useFavoriteSpreads` (2), `useUserSpreads` (4),
    `useDecks` (1); views `AccountSettingsView`, `admin/DeckSystemsView`,
    `ContactView`, `CustomReadingView` (2), `FreeDrawPlacementView` (3),
    `NewReadingView`, `ReadingView` (4), `SubmitDeckSystemView`,
    `SubmitDeckView` (2), `SubmitSpreadView`. The 429 branch is pasted into
    `ContactView.vue:45` and `SubmitSpreadView.vue:52`; error-message parsing is
    re-implemented per site; several swallow errors. `AGENTS.md` §5 already says
    to use `apiRequest`/`apiFetch`.
  - **Fix:** migrate each to `apiRequest` (keep `apiFetch` for list reads); add
    `useReading`/`useContact`-style composables where a view owns the call;
    then lock it in with an ESLint `no-restricted-syntax` rule forbidding
    `fetch(` outside `composables/apiClient.ts`.
  - **Verify:** `grep -rn "fetch('/api" src` → only `apiClient.ts`; lint rule
    fails on a new raw fetch.
  - **DONE 2026-10-28.** 34 were left (the bug batch had moved some); all are
    on `apiRequest`/`apiFetch` now, through composables: new `useReadings`
    (generate, custom, get, unlock, draw more, finalize, placement) and
    `useSubmissions` (deck, deck system, spread — the spread submit was pasted
    into two views), `useDecks.fetchDeckCards`, `useAccount.unlinkGoogle`, and
    `useAccount`/`usePasskeys`/`useUserSpreads` rewritten (each had its own
    `parseJson` + `JSON_HEADERS` copy). `sendJson()`/`sendForm()` in
    `apiClient.ts` replace four copies of the JSON-body helper. The pasted 429
    branch is gone: the server's message *is* that text. Errors that were
    swallowed now surface (deck systems failing to load on Submit a Deck, a
    deck's cards on Recreate Draw, `useUserSpreads` no longer throws offline),
    and a rate-limited reading no longer says "we couldn't find that reading".
    `usePasskeys` now builds typed WebAuthn options instead of mutating `any`.
    Lint rule `tarot/api-client-only` (`no-restricted-syntax` on `fetch(` and
    `window|globalThis|self.fetch`, outside `apiClient.ts` and specs) — tested
    to fail on both forms. New specs: `useReadings`, `useSubmissions`,
    `usePasskeys` (byte conversion both ways, cancel, server reasons),
    `useUserSpreads`, more `useAccount`. Clicked through live on dev: new draw,
    draw more, mark final, free-draw placement + public spread submission,
    Recreate Draw's deck cards, account settings — all working.

- [x] **4.3 The admin list-page scaffold is repeated in nine views.** [low] [M]
  - **Where:** back link + `PageHeader` + search field + `.settings-panel` +
    table + empty-state in `views/admin/{Changelog,Contacts,Decks,DeckSystems,
    ErroredCards,Readings,SpecialCards,Spreads,Users}View.vue`.
  - **Fix:** `components/admin/AdminListPage.vue` with `title`/`subtitle`/
    `searchPlaceholder` props and `actions`/`default`/`empty` slots; views keep
    their table bodies.
  - **Verify:** each page renders identically (screenshots), line count drops.
  - **DONE 2026-10-28.** `AdminListPage` (column, back link, `PageHeader` with an
    `#actions` slot that only renders when used — `PageHeader` drops its actions
    box otherwise) and `AdminSearchField` (optional `label`, for Special Cards'
    filter row). Converted by script so the DOM stayed identical; the
    computed-style walk shows changelog, contacts, decks, deck systems, special
    cards and users **identical** to before. Views: −211 lines, +61 shared.
    **Manage Spreads changed on purpose:** it was the one admin page with no
    column (full container width) and a hand-rolled Bulma `level` heading; it
    now has the same width, `PageHeader` and gold rule as the other eight, and
    its add/edit mode uses the same frame.

- [x] **4.4 Two type systems, one of them dead.** [medium] [M]
  - **Where:** `src/types/index.ts` (251 lines, hand-maintained) and
    `src/types/api.generated.ts` (regenerated and diff-checked in CI on every
    run, **imported by nothing**).
  - **Fix:** pick one. Recommended: add `required: [...]` to the `#[OA\Schema]`
    attributes on the Structures (swagger-php emits optional props otherwise, so
    the generated types are currently all `?`), then derive
    `export type CardReport = components['schemas']['CardReport']` etc. in
    `types/index.ts` and delete the hand copies. Otherwise delete the generator,
    the npm script, and the CI step.
  - **Verify:** `npm run type-check`; one source of truth.
  - **DONE 2026-10-28.** Rather than 14 hand-kept `required: [...]` lists, a
    swagger-php processor (`Routes\RequireStructureProperties`, run by
    `composer docs` and OpenApiSpecTest) marks every property of a
    `Tarot\Structure` schema required — true by construction, since
    `jsonSerialize()` sends them all. Spread `positions` were "array of object";
    a `SpreadPosition` schema (in `OpenApiSpec.php`, the shape
    `NormalizesPositions` stores, with descriptions) now types them and the
    five request bodies that take positions. `types/index.ts` re-exports 15
    entity types from the generated file and deletes their hand copies; the
    reading-view payload, `AccountReading`, `UsageStats` and client shapes stay
    hand-written for now.
  - **Follow-up, done the same day:** the reading endpoints were documented
    with the stored-row `Reading` schema while get/unlock/generate/draw/finalize
    send `accessiblePayload()` and GET can send the locked stub. New schemas in
    `OpenApiSpec.php` — `ReadingView`, `LockedReading` (GET is a `oneOf` of the
    two), `ReadingInfo`, `DrawCard`, `SpreadSnapshot` — every property described;
    `Reading.reading_info` now references `ReadingInfo`. `card_name` is honestly
    optional (the resolver omits it for an unnamed extra card), so the frontend's
    `ReadingCard` gets `cardLabel()` ("Card #N") instead of a blank caption. The
    frontend now takes all six reading types from the spec — no hand-written API
    types are left except `UsageStats` and client shapes.
    `tests/Http/ReadingPayloadsTest` reads each endpoint's documented response
    from `openapi.json` and compares real responses with it, nested objects
    included; run against the old spec it fails on exactly the mismatches.
  - **Found while doing that — frontend type-checking had been off.**
    `npm run type-check` ran `vue-tsc --noEmit` on the solution-style root
    `tsconfig.json` (`"files": []`), which checks no files. Checking the app
    project for real gave 599 errors, nearly all "`vue` has no exported member
    `ref`": `vite-env.d.ts` had no top-level import/export, so its
    `declare module 'vue' { GlobalComponents }` *replaced* Vue's types instead of
    augmenting them. Not a Vue/TypeScript version problem — Vue 3.5.44 (latest
    stable) with TypeScript 6.0.3 compiles cleanly in isolation. The augmentation
    moved to `src/global-components.d.ts` (a module); the script now checks
    `tsconfig.app.json` and `tsconfig.node.json`, and a deliberate error in a
    `.ts` and a `.vue` file both fail it. That left 14 real errors, fixed: a
    TipTap 3 bug (`setContent(html, false)` — v3 takes an options object, so
    `emitUpdate: false` was ignored and syncing the prop echoed an update back),
    `SegmentedControl` assigning a plain string to its generic model (it now
    takes the matching option's value), three admin forms passing a possibly
    missing id into a URL, `lib`/`target` still ES2020 under code that ships
    `.at()`/`Object.hasOwn` (now ES2022, which Vite 8's browser targets have),
    the reading types above, and a spec's mock signature. With real types, typed
    ESLint surfaced 29 more: `Number()`/`String()` wrappers left from when
    everything was `any` (removed where the value is a typed number/string; the
    `perPage` select was checked to hand back numbers), a floating `nextTick`,
    `as` casts replaced by a typed form interface, Turnstile's after-`await`
    element re-check kept but made to read the ref fresh, and indexes that can
    miss typed honestly (`deckLookup` is `Partial`, `.at()` for maybe-empty).

- [x] **4.5 `AdminController` is 1,505 lines / 45 methods.** [medium] [M]
  - **Fix:** split per resource to mirror the admin views and the route groups
    already in `api/index.php:252-318`: `AdminDashboardController`,
    `AdminDeckController` (+ special cards, thumbnails), `AdminDeckSystemController`,
    `AdminSpreadController` (+ pending), `AdminReadingController`,
    `AdminUserController`, `AdminContactController`, `AdminChangelogController`,
    `AdminCardReportController`. Mechanical; keep the `OA\Tag`s so the spec
    doesn't move.
  - **Verify:** `composer docs` produces an identical `openapi.json`
    (operationIds are hash-based — expect them to change; paths/schemas must not).
  - **DONE 2026-10-28.** Split by script into the nine controllers named above
    (special cards and card images inside `AdminDeckController`, pending spreads
    in `AdminSpreadController`, the session check in `AdminDashboardController`),
    each with only the dependencies its methods use. Checked mechanically: all
    44 methods moved with byte-identical bodies and attributes; the only lines
    lost are the nine section headings each class now replaces. `openapi.json`:
    paths, components and tags identical; the 44 admin operationIds changed, as
    predicted. All 16 admin GET routes answered 200 on dev afterwards.

- [x] **4.6 The gold tint is typed out 39 times.** [medium] [S]
  - **Where:** `rgba(201, 162, 75, α)` at eight alphas (.06 .1 .12 .2 .25 .4 .55
    .6) across `style.css`, `HomeView.vue` (27 colour literals in its 666-line
    style block), `App.vue` (10), and six components; aqua `rgba(67, 179, 167, α)`
    likewise. This contradicts the project's own "tokens.css is the palette
    source of truth" rule.
  - **Fix:** add an alpha scale to `tokens.css` (`--myst-gold-a06` … `--myst-gold-a60`,
    or use `color-mix(in srgb, var(--myst-gold) 12%, transparent)` and keep one
    token); replace the literals; then enforce with Stylelint (§6.1).
  - **Verify:** `grep -rhoE "rgba\(201, ?162, ?75" src` count is 0 outside
    `tokens.css`.
  - **DONE 2026-10-28 — and the count is 0 inside `tokens.css` too.** 62 palette
    copies replaced by `color-mix(in srgb, var(--token) N%, transparent)`, alpha
    for alpha, by script: 39 gold, 12 aqua, 5 status tints (info/success/
    warning/danger), 2 surface-3, and `tokens.css`'s own derived tints
    (`--myst-hair-gold`, the aqua washes) — so each colour is defined once.
    `color-mix` over `transparent` computes to the same colour; the style walk
    confirms no element changed. Off-palette leftovers, which **do** change
    slightly: the lightbox caption/counter/hint greys (`#ddd/#999/#888` → text
    tokens) and its orange "(Reversed)" (`#f4a261` → `--myst-warning`, what the
    reading page already uses for it); the toggle's bluish border (`#4a4f63` →
    `--myst-border-strong`); pure-white headings (spread details, changelog
    titles → `--myst-text-strong`). White stays where it sits on a fill or on
    card art. Deleted as dead: the five `.notification.is-warning:not(.is-light)
    .toggle-*` rules (no such notification exists). Stylelint enforcement waits
    for §6.1.

- [x] **4.7 Card-report reason copy lives in three places by design** (frontend
  `utils/cardReports.ts`, backend `CardReportReason`, plugin `ApiModels.cs`).
  [low] [S] Add a Vitest that reads `backend/openapi.json` and asserts the
  frontend keys equal the `CardReportReason` enum so they cannot drift.
  - **DONE 2026-10-28, differently.** With §4.4 the frontend's
    `CardReportReason` *is* the spec's enum, and `REASON_COPY` is a
    `Record<CardReportReason, …>` — a new backend reason fails type-checking
    until it has copy, which is stronger than the suggested test. The unguarded
    copy was the plugin's: `tests/Unit/Structure/CardReportReasonTest.php`
    reads `ApiModels.cs` and asserts its keys equal the enum's cases.

---

## 5. Standards and consistency

- [ ] **5.1 `composer.json` has `"*"` version constraints** on `slim/slim`,
  `slim/http`, `slim/psr7`, `php-di/slim-bridge`, `friendsofphp/php-cs-fixer`,
  `squizlabs/php_codesniffer`. [medium] [S] Pin to the installed majors
  (`composer show -D` for current versions) with caret ranges.
  - *Status 2026-10-28:* `squizlabs/php_codesniffer` is gone (§5.2); the other
    five still read `"*"`.

- [x] **5.2 php-cs-fixer has no config file.** [medium] [S]
  `composer lint` runs phpcs (PSR-12, warnings ignored); `composer lint:fix`
  runs php-cs-fixer with **no** `.php-cs-fixer.dist.php`, so the fixer and the
  checker don't agree on a ruleset. Pick one: recommended php-cs-fixer with a
  dist config (`@PSR12` + `@PHP8x` risky set as tolerated), `php-cs-fixer check`
  in CI, and drop phpcs; or keep phpcs and remove the fixer.
  - **DONE 2026-10-28 — php-cs-fixer, phpcs removed.** Measured first: the two
    tools disagreed in 26 files (php-cs-fixer's own `@PSR12` flagged code phpcs
    passed). `backend/.php-cs-fixer.dist.php`: `@PER-CS3x0` (PSR-12's successor,
    pinned) + `@PHP8x5Migration` + `no_unused_imports`, with three house-style
    overrides so adopting it didn't restyle working code: casts stay `(int)$x`
    (344 of 347 casts), empty constructor bodies keep their own lines (44
    files), and multi-line *call arguments* take no trailing comma (61 files;
    arrays already did, everywhere). The risky set was left out: it would add
    `declare(strict_types=1)` to 139 files, a behaviour change, not a format.
    One pass fixed 26 files — mostly PHP 8.4's `new Foo()->bar()`, `fn(`
    spacing, `0o775` octals, a few trailing commas on parameter lists. Scope is
    every committed PHP file (`api`, `includes`, `scripts`, `tests`, `og.php`,
    `dev-router.php`); phpcs only looked at three directories. `composer lint` =
    `php-cs-fixer check`, `lint:fix` = `fix`; CI step renamed; `phpcs.xml` and
    `squizlabs/php_codesniffer` removed; cache gitignored. Lost: phpcs's 120-char
    line warning, which was already set not to fail anything.

- [ ] **5.3 Missing project metadata.** [low] [S] `engines` in
  `frontend/package.json` + `.nvmrc` (CI pins Node 24); root `.editorconfig`;
  `plugin/.editorconfig`.

- [ ] **5.4 Documentation drift (the spun-off docs task landed nothing).** [low] [S]
  - `AGENTS.md`: §3 says `eslint.config.js` (it is `eslint.config.ts`, consuming
    the `frontend/eslint-config` submodule); the §3 tree comment says PHPStan
    "level 6" while §6 says level 8 (8 is right); §12 still describes
    `ShareRelay`, `SharePrompt`, `ClientAuth`, and a per-install client token.
  - `plugin/README.md`: lines ~6, ~21, ~78 still advertise the chatless share
    relay, consent tiers, and the client token.
  - `plugin/TarotGen.Plugin/TarotGen.Plugin.json` Description: "Optional account
    linking (later)" — linking shipped.
  - `plugin/TarotGen.Plugin/Configuration.cs` docblock references `docs/auth.md`,
    which does not exist.
  - `backend/.env.example`: the `PLUGIN_IDENTITY_SALT` block (§2.1).
  - **Partly done with §2.1:** AGENTS.md §12 no longer describes the relay
    (layout list matches the plugin's real files; auth paragraph rewritten) and
    the `.env.example` block is gone. The PHPStan-level line in AGENTS.md §3 was
    fixed with §5.2. **Still open (checked 2026-10-28):** AGENTS.md §3 tree says
    `eslint.config.js`; `plugin/README.md` still advertises the chatless share
    (line ~21) and the client token / relay (~78–82); `TarotGen.Plugin.json`
    still says "Optional account linking (later)"; `Configuration.cs` still
    points at the missing `docs/auth.md`.

- [x] **5.5 `contactForm.spec.ts` tests a re-implementation, not the code.** [low] [S]
  The spec defines its own `submitContact()` with its own fetch logic and tests
  that. It cannot fail when `ContactView.vue` changes. Replace with a test of
  the composable §4.2 introduces, or a `@vue/test-utils` mount.
  - **DONE 2026-10-28** (with §8.5): the submit logic moved into
    `composables/useContact.ts` (on `apiRequest`; same user-facing messages),
    `ContactView` calls it, and `useContact.spec.ts` replaces the old spec —
    request shape incl. trimming, server message, 429, body-less 500, network
    error. Submitted for real on dev → success panel.

- [ ] **5.6 Accessibility basics.** [low] [S] No `<main>` landmark anywhere in
  `src/`, no skip link. Add both in `App.vue`; add
  `eslint-plugin-vuejs-accessibility` (§6.1). Reka already covers the widgets.

- [x] **5.7 Error-swallowing composables.** [low] [S] `useDecks.ts:32` and
  friends (`useSpreads`, `useFavorite*`) catch and drop failures. Return an
  `ApiResult` or toast; §4.2 is the natural moment.
  - **DONE 2026-10-28:** `useDecks`, `useSpreads`, `useFavoriteDecks`,
    `useFavoriteSpreads` (and `useAccount.listReadings` — §2.6) now use
    `apiRequest` and toast what failed; favourites stay quiet on 401 (expired
    session, the router handles it). `useDecks` shares one in-flight request
    and retries on the next call. `toggleFavorite` (called fire-and-forget by
    the comboboxes) toasts on failure and can no longer reject. Specs:
    `useDecks.spec.ts` (rewritten), `useFavoriteDecks.spec.ts`. Seen on dev with
    the API stopped: toasts instead of empty pickers. Known rough edge: a
    *total* outage on New Draw stacks four toasts (decks, spreads, two
    favourites) — accurate but loud; group them if it ever matters.

- [x] **5.8 Native controls render light on the dark theme.** [low] [S] No
  `color-scheme` is declared anywhere (`getComputedStyle(html).colorScheme`
  is `normal`), so scrollbars inside tall modals and native form chrome are
  white-on-charcoal. Add `color-scheme: dark` at `:root` in `tokens.css` and a
  restrained `scrollbar-color` using existing tokens. (This was one of the three
  tasks that never landed.)
  - **DONE 2026-10-28:** `:root` in `tokens.css` declares `color-scheme: dark`
    and `scrollbar-color: var(--myst-border-strong) transparent` (inherited;
    colour only, so the modal scroll-lock widths are untouched). Computed on dev:
    `colorScheme` = `dark`, scrollbar `rgba(255,255,255,.16) transparent`.

- [ ] **5.9 Slim's own errors don't use the `{ error }` shape.** [low] [S]
  Found by the §8.1 tests. A route or method that doesn't exist answers
  `{"message": "404 Not Found"}` / `"405 Method Not Allowed"` (plus an
  `exception` array outside production), or an HTML page when the request
  doesn't ask for JSON, while API.md promises `{ "error": "…" }` and every
  controller sends that. No visible harm today: the frontend's
  `messageFromBody()` reads only `error` and falls back to its own text. Fix: a
  JSON error renderer for the default handler that emits `{ error }` (detail
  only outside production), then tighten `RequestPipelineTest`'s 404/405 test.

---

## 6. Tooling and linting gaps

Nothing currently lints CSS, nothing finds unused code or dependencies, the
plugin is never compiled in CI, and no bot watches dependencies.

- [ ] **6.1 Frontend.** [medium] [S–M]
  - **Knip** (unused files/exports/dependencies). Install as a devDependency so
    it runs offline and in CI (`npm run knip`). It would have flagged
    `api.generated.ts` (§4.4) immediately.
  - **Stylelint** with `stylelint-config-standard`, `stylelint-config-recommended-vue`,
    `postcss-html`; add `color-no-hex` and a
    `declaration-property-value-disallowed-list` entry for `rgba(`/`rgb(` with
    an override that allows them only in `src/assets/tokens.css`. That
    mechanically enforces the token rule (§4.6).
  - **Coverage**: `@vitest/coverage-v8`, `test:coverage` script, thresholds set
    at today's numbers and ratcheted.
  - `eslint-plugin-vuejs-accessibility`.
  - **Playwright** smoke test (§8.6).
  - *Status 2026-10-28:* none of the above yet. Related, done: `npm run
    type-check` actually type-checks now (it checked no files before — §4.4), and
    the `tarot/api-client-only` lint rule bans raw `fetch` (§4.2).

- [ ] **6.2 Backend.** [medium] [S–M]
  - PHPStan extensions via `phpstan/extension-installer`: `phpstan-strict-rules`,
    `phpstan-deprecation-rules` (would have caught §1.2 at analysis time if the
    Structures were analysed against real rows — at minimum it flags deprecated
    API use), `phpstan-phpunit`.
  - **Rector** (`rector/rector`) with the PHP 8.5 level set + dead-code set,
    `--dry-run` in CI.
  - `composer-unused` + `composer-require-checker` (implicit deps, e.g. `ext-gd`
    for `CardImageService`, `ext-mbstring`, `ext-json` are not declared).
  - `ergebnis/composer-normalize`.
  - **`composer audit` as a CI step** — green since §7.1, so it can gate now.
    *Done 2026-10-28 (§7.1).*
  - Coverage with `pcov` in CI.
  - *Also done since:* one code-style tool (§5.2); PHPStan level 8 now covers
    all of `api/` and `tests/Http` (§8.1). The extensions, Rector, the
    unused/require checkers, composer-normalize and coverage are still open.

- [ ] **6.3 Plugin.** [medium] [S]
  - `TarotGen.Plugin.csproj`: `<TreatWarningsAsErrors>true</TreatWarningsAsErrors>`,
    `<AnalysisLevel>latest-recommended</AnalysisLevel>`,
    `<EnforceCodeStyleInBuild>true</EnforceCodeStyleInBuild>`; a `.editorconfig`
    with the C# style rules.
  - **A CI job that builds it.** Today a plugin compile break ships silently.
    The standard goatcorp workflow downloads the Dalamud dev distribution
    (`https://goatcorp.github.io/dalamud-distrib/latest.zip`) into `DALAMUD_HOME`
    and runs `dotnet build -c Release`; upload `latest.zip` as an artifact.

- [ ] **6.4 Repo.** [low] [S] `.github/dependabot.yml` covering `npm`
  (`/frontend`), `composer` (`/backend`), `nuget` (`/plugin/TarotGen.Plugin`),
  and `github-actions`. CodeQL if the plan allows it.

- [ ] **6.5 "Kindred Nights"** was mentioned as a candidate linter; it is not a
  tool I recognise. If it meant **Knip**, see §6.1. Otherwise, name the tool and
  evaluate it against the gaps above rather than adding it on principle.

---

## 7. Outdated dependencies (as of 2026-10-27)

- [x] **7.1 `composer audit` fails: HIGH CVE-2026-109413 in `symfony/yaml`**
  (transitive via `friendsofphp/php-cs-fixer`, dev-only, but red). [high] [S]
  `composer update friendsofphp/php-cs-fixer symfony/yaml` and re-run.
  - **Correction:** `symfony/yaml` comes in through `zircote/swagger-php`
    (`composer why`), not php-cs-fixer. Both are `require-dev`, and the deploy
    runs `composer install --no-dev`, so it never reached production; it only
    ran in `composer docs`.
  - **DONE 2026-10-28:** `symfony/yaml` 8.1.2 → 8.1.9 and
    `roave/security-advisories` refreshed. That refresh first failed: the
    advisories now flag PHP itself below 8.5.12, and `config.platform.php` was
    still pinned to 8.5.6. The pin is now **8.5.12**, matching production
    (`php -v` on the droplet), local and CI. `composer audit` is clean;
    `composer docs` produces an identical `openapi.json`; tests, PHPStan and
    phpcs pass. `composer audit` now runs in CI after the install step.
  - **Production check (read-only, 2026-10-28):** `composer audit --no-dev` on
    the installed packages is clean; `vendor/` matches the lock; no dev packages
    present; Apache's `libapache2-mod-php8.5` and the CLI are both 8.5.12 with no
    pending php/apache/openssl/sqlite upgrades. One runtime update was still
    warranted — see §7.2 (`slim/slim` 4.15.3).
- [ ] **7.2 Composer, direct:** `zircote/swagger-php` 6.6.0 → 6.20.0 (14 minors
  behind — regenerate and diff `openapi.json` after), `phpstan/phpstan`
  2.2.8 → 2.3.3, `phpunit/phpunit` 13.3.1 → 13.4.5, `php-cs-fixer`
  3.95.20 → 3.95.27, `slim/slim` 4.15.2 → 4.15.3. [low] [S]
  - **`slim/slim` 4.15.3 — DONE 2026-10-28** (runtime; ships on the next backend
    deploy). Not in the advisory database, but the release fixes
    GHSA-h377-p8x2-prf9: route arguments were URL-decoded twice, bypassing route
    regex constraints. This API has no constraints, so the exposure was a
    double-decoded `{reading_id}` etc. reaching controllers (int casts / bound
    parameters); low, but worth the patch. Smoke-tested on the dev server:
    `/readings/a3921cc26%2566` now 404s instead of resolving to `…26f`; 404/405
    still negotiate JSON for `Accept: application/json`. Tests/PHPStan/phpcs pass,
    `openapi.json` unchanged. `laravel/serializable-closure` 2.0.15 → 2.1.0
    (php-di's, used to compile the prod container) left alone: bug fix + PHP 8.6
    support only.
  - *Still behind (`composer outdated --direct`, 2026-10-28):* php-cs-fixer
    3.95.20 → 3.95.27, phpstan 2.2.8 → 2.3.3, phpunit 13.3.1 → 13.4.5 (all
    dev-only); `zircote/swagger-php` 6.6.0 now has a **7.0** major out — plan it
    with a spec diff, since it generates `openapi.json`.
- [ ] **7.3 npm:** 20 packages behind. Minors: `vue` 3.5.44, `vue-router` 5.4.0,
  `vite` 8.3.5, `reka-ui` 2.11.0, `@tiptap/*` 3.32.0, `dompurify` 3.4.17,
  `marked` 18.1.2, `eslint` 10.13.0, `vue-tsc` 3.3.13, `@vitejs/plugin-vue`
  6.0.10, `sass-embedded` 1.106.1, `jsdom` 30.1.3, `prettier` 3.9.10.
  **Majors (plan separately):** `typescript` 6.0.3 → 7.0.2, `vitest`
  4.1.11 → 5.0.5, `happy-dom` 20.11.2 → 21.0.4. [low–medium] [S–M]
  - **Vue/Vite done 2026-10-28** (lock only — all in range): `vue` 3.5.44,
    `vite` 8.3.5, `@vitejs/plugin-vue` 6.0.10, `vue-router` 5.4.0, `vue-tsc`
    3.3.13. All MIT on the registry; the only new transitive dep is `confbox`
    (MIT). TypeScript stays 6.0.3 — `package.json` pins `~6.0.0` (`vue-tsc`'s
    own peer range is just `>=5`, so the pin is the guard; owner: wait for TS 7.1).
    type-check, lint, 135 tests, build pass; home → New Draw (deck combobox,
    generate) → reading → lightbox clicked through on dev, no Vue warnings.
    *(Correction: "type-check" checked no files at the time — see §4.4. Once it
    was made real, Vue 3.5.44 + TypeScript 6.0.3 type-checked clean after fixing
    a broken `vue` augmentation in this repo; the versions were never the
    problem. Verified separately in an isolated project.)*
  - **`npm audit` (found while doing the above; pre-existing):** 36 advisories,
    1 critical / 5 high. Shipped code: `dompurify` (critical + high — but both
    need `SAFE_FOR_TEMPLATES`/`IN_PLACE`+hooks; `utils/markdown.ts` uses defaults),
    `prosemirror-view` paste XSS (reachable: TipTap is on the public contact
    form and reading notes — needs the victim to paste crafted content),
    `@tiptap/core` ReDoS. Dev/test only: `undici`, `brace-expansion`,
    `@vitest/mocker`, `postcss-selector-parser`, `happy-dom` (major). Every
    shipped fix is in range → `npm audit fix` (no `--force`), as its own commit.
  - **Audit fix done 2026-10-28:** `npm audit fix` took 36 → 8, but left the
    TipTap family split — `starter-kit` on 3.32.0 while `extension-link`,
    `extension-placeholder`, `pm` and `vue-3` stayed on 3.30.2 with a second,
    vulnerable `@tiptap/core`. Installed all five at `^3.32.0` (one core now) and
    `vitest` at `^4.1.12` (patched `@vitest/mocker`). **Shipped deps: 0
    vulnerabilities.** Left: `happy-dom` (dev/test, needs 21 — a major, planned
    with the other majors). type-check, lint, 135 tests, build pass; the TipTap
    editor (contact form) typed + toggled Bold → `<p>… <strong>…</strong></p>`.
    *(The real type-check later found one TipTap 3 API change this had missed —
    `setContent(html, false)` — fixed in §4.4.)*
  - *Still behind (`npm outdated`, 2026-10-28):* in range — `@tiptap/*` 3.32.1,
    `eslint` 10.13.0, `reka-ui` 2.11.0, `marked` 18.1.2, `prettier` 3.9.10,
    `sass-embedded` 1.106.1, `jsdom` 30.1.3. Majors — `typescript` 7 (owner:
    stay on 6 until Vue supports TS 7.1), `vitest` 5, `happy-dom` 21.
  - **SQLite on prod is 3.45.1 and stays there on 24.04:** PHP (packages.sury.org)
    links the distro `libsqlite3`, and noble only gets 3.45.1 security patches.
    26.04 LTS (offered to the droplet) ships 3.46.1 — enough for `PRAGMA
    optimize`'s check-every-table bit, little else. Not a reason to upgrade the
    OS; §1.7 uses plain `ANALYZE` instead. A self-built lib in `/usr/local/lib`
    would shadow the distro one for every program and lose apt security updates.
- [ ] **7.4 Plugin:** `Dalamud.NET.Sdk` 15.0.0, `System.Security.Cryptography.ProtectedData`
  10.0.11 — **unverified** (NuGet was not reachable from the audit sandbox).
  `dotnet list package --outdated` from a networked shell.

---

## 8. Tests

At audit time: backend 240 tests / 638 assertions (unit only); frontend 132
tests across 17 specs (utils + composables only). **Now (2026-10-28, end of
day): backend 301 tests / 1,163 assertions (unit + 34 HTTP); frontend 166 tests
across 24 specs** (still utils + composables only). Coverage is still not
measured anywhere (`coverage: none` in CI).

- [x] **8.1 No HTTP-level tests at all.** [high] [M]
  All 14 controllers and all three auth middleware (`UserAuth`, `AdminAuth`,
  `AccountAuth`) are untested — `AdminController`'s 1,505 lines included.
  **Fix:** extract app construction from `api/index.php` into `api/app.php`
  (returns `$app`; `index.php` becomes `require 'app.php'; $app->run();`), then
  a PHPUnit base case that builds the app against an in-memory SQLite seeded
  from `db/schema.sql` and dispatches PSR-7 requests. First targets: 401 shapes
  from each middleware, `OriginGuard` end-to-end, validation 400s, card-reports
  201/200/400/429, one admin CRUD round-trip.
  - **DONE 2026-10-28 (the harness + the first targets).** `api/app.php` returns
    a factory `(container, is_production) → App` holding the middleware, error
    handling and every route; `index.php` keeps the process setup (UTC, env,
    cookie params, container compilation) and runs it. The `/openapi.json` and
    `/docs` closures became `ApiDocsController` (top-level `const`s can't be
    re-declared each time a test builds the app). The one production change the
    harness needed: `Session` took its store from the `Connection` singleton, so
    `app.php` now hands it the container's PDO (`Session::useDatabase`) and
    sessions follow whatever database the app was built on.
  - `tests/Http/HttpTestCase` (new `http` suite): schema.sql in memory with
    foreign keys on, as on prod; each test is one browser whose session id
    carries from request to request, with sessions really stored by
    `DbSessionHandler` (in-process works because PHPUnit writes to the stdout
    stream, so PHP never marks headers as sent); env blanked per test; a
    per-test documentation-range IPv6 client address so the file-backed rate
    limiters don't collide; Slim/mailer logging sent to a temp file.
  - 30 tests, ~1.6 s: `AuthGuardsTest` (admin/user/account 401 bodies,
    inactive and deleted accounts, demotion taking effect next request, plugin
    tokens open only the three read routes, unknown/revoked tokens),
    `RequestPipelineTest` (OriginGuard by Origin and by Referer, before routing
    and before auth, same-origin/no-header/GET pass, `APP_ORIGIN` + CORS,
    security headers, 404/405 as JSON), `CardReportsTest` (201 with reason,
    reason-less bump, 200 cooldown, four 400s, 429 after 20),
    `AdminChangelogTest` (create → public read → update → delete; 404; reader
    401 writes nothing), `SignInFlowTest` (register → 403 inactive → activate →
    401 wrong password → login → me → logout; new session id on sign-in, the
    pre-login one carries no user; duplicate 422), `ApiDocsTest`. PHPStan level
    8 now covers `api/` whole and `tests/Http` (tests/Unit has ~170 findings and
    stays out for now).
  - **Found by the new tests — fixed:** a card report for a deck that no longer
    exists (a stale tab, an old plugin build) failed the `card_reports` foreign
    key as a **500** with a stack trace in the error log; it is now a 400 ("A
    valid deck and card are required."). Verified on the dev server too.
  - **Found — open, see §5.9:** Slim's own 404/405 bodies aren't `{ error }`.
  - **Noticed, left alone:** `POST /admin/changelog` accepts an entry with no
    title (the spec says `title` is required; admin-only, so low).
  - Next targets, by risk: `AccountController` (ownership: one user's
    readings/spreads vs another's), `ReadingController` (draw/finalize/unlock,
    password-protected readings), `PluginAuthController` (PKCE code → token),
    the other eight admin controllers (§4.5 split them; only changelog has
    HTTP tests).
  - *Since:* `ReadingPayloadsTest` (4 tests, §4.4 follow-up) checks every
    reading endpoint's response against its documented schema — 34 HTTP tests
    in all.
- [ ] **8.2 Untested Data classes:** `ChangelogData`, `SpecialCardData`,
  `FavoriteDeckData`. [low] [S] Same in-memory pattern as `CardReportDataTest`.
- [ ] **8.3 Zero component/view tests on the frontend.** [medium] [M]
  Add `@vue/test-utils` and start with the interactive components:
  `CardReportDialog`, `LightboxOverlay` (keyboard + report gating),
  `DeckComboBox`, `ConfirmDialog`, `ToggleSwitch`, `SegmentedControl`.
- [ ] **8.4 Untested composables:** `useAccount`, `useChangelog`,
  `useFavoriteDecks`, `useFavoriteSpreads`, `usePanZoom`, `usePasskeys`
  (272 lines; mock `navigator.credentials`), `usePluginLink`,
  `useReadingExport` (393 lines; canvas — at least the layout math),
  `useSpreads`, `useUserSpreads`, `utils/seo.ts`. [medium] [M] Easier after §4.2.
  - *Status 2026-10-28:* now covered — `useAccount`, `useFavoriteDecks`,
    `usePasskeys`, `useUserSpreads` (plus the new `useContact`, `useReadings`,
    `useSubmissions`). **Still untested:** `useChangelog`, `useFavoriteSpreads`,
    `usePanZoom`, `usePluginLink`, `useReadingExport`, `useSpreads`,
    `utils/seo.ts`.
- [x] **8.5 Replace `contactForm.spec.ts`** (§5.5). [low] [S]
- [ ] **8.6 One Playwright smoke run in CI:** home → new draw → reading →
  lightbox → report a problem → (seeded admin) Errored Cards shows it. [medium] [M]
  This app is interaction-heavy; one end-to-end path catches whole classes of
  regressions the unit layer can't.
- [ ] **8.7 Coverage in both CI jobs with thresholds.** [low] [S]

---

## 9. Product gaps (tools for readers — no interpretations)

Ranked by value against cost. Each is a thin slice over existing data.

- [ ] **9.1 Journal fields and findability.** [high] [M]
  Add `question` (what was asked) and `querent` (who it was for, free text) to
  readings, plus tags; then search, filter (deck, spread, tag, date range,
  text in notes/question) and paging in **My Readings**. Readers keep journals;
  today a reading has a name and notes, and the list is already unbounded (§2.6).
- [ ] **9.2 Card frequency for an account.** [high] [S]
  "Which cards keep turning up" over the user's own readings (optionally per
  deck / per time window). One `json_each` query; readers who journal ask for
  exactly this.
- [ ] **9.3 Pick-your-own-cards draw.** [high] [L]
  Fan the shuffled deck face-down and let the user tap cards to fill positions
  in order, instead of the server dealing the top N. Server stays authoritative
  (shuffle once per reading, store the permutation, reveal on pick). The
  single biggest ritual gap for people who actually do readings.
- [ ] **9.4 Reversal rate setting** (none / light / standard / heavy, e.g.
  0/15/27/50%) instead of a boolean. [medium] [S] `ReadingService::REVERSAL_TURN_PROBABILITY`
  already parameterises it; plumb a per-request value with a server-side clamp.
- [ ] **9.5 Public deck pages** (`/decks/{id}`: name, artist, purchase link,
  card gallery). [medium] [M] `GET /decks/{id}/cards` already exists. Preview
  before drawing, SEO landing pages, and a natural home for "report a problem".
- [ ] **9.6 Table / presentation mode** for in-person readings: full screen,
  chrome hidden, large cards, keyboard flip and advance, plus a QR code for the
  share link (readers hand a phone to the client). [medium] [M]
- [ ] **9.7 Privacy levels and session control.** [medium] [M]
  Unlisted / private (today: public-by-link or password), optional expiry on
  guest readings, and "sign out everywhere" (the `sessions` table makes the
  last one trivial).
- [ ] **9.8 Daily card** with a calendar view of past draws. [low] [S]
- [ ] **9.9 Spread library polish:** categories/tags + search, fork-a-spread
  into My Spreads, per-position descriptions (today titles only). [low] [M]
- [ ] **9.10 Export:** print stylesheet / PDF for a reading; JSON (and CSV)
  export of the whole journal from Account Settings. [low] [S–M]
- [ ] **9.11 Admin:** open-card-reports count on the dashboard (the code notes
  it is missing, `admin/DashboardView.vue:42`), and a "re-scanned → regenerate
  thumbnail" action on Errored Cards rows wired to the existing
  `POST /admin/decks/{id}/images` (renamed from `…/thumbnails` in §3.2), so the
  reporting loop closes in one click.
  [low] [S]
- [ ] **9.12 PWA offline for saved readings.** [low] [L] `sw.js` is a
  pass-through today (correctly, to avoid stale deploys); a reading-scoped cache
  is a later project.

---

## 10. Suggested order

The original order (prod reconnaissance → §0, §1, §2, §3, §4, §8.1) is done
except §1.7's first live run. **Remaining, revised 2026-10-28 (end of day):**

1. **Tonight:** tick §1.7 once `journalctl -t tarotgen-retention` shows the
   first real run after 03:17 UTC 2026-10-29. Owner's housekeeping: §0 worktrees,
   branches, stash.
2. **Before the next backend deploy:** `sudo tarotgen-backup`, then §2.3's and
   §2.7's migrations as www-data.
3. **Small fixes (each S):** §5.9 Slim error shape, §5.1 version constraints,
   §5.4 the leftover plugin/AGENTS doc lines, §5.3 metadata, §5.6 `<main>` +
   skip link.
4. **Tests:** §8.1's next targets (account ownership, reading flows, plugin
   PKCE, the other admin controllers), §8.2, §8.4's remainder, then §8.3
   component tests and §8.6 Playwright; §8.7 coverage with §6.1/§6.2.
5. **Tooling that stops regressions:** §6.1 Stylelint (enforces §4.6) + Knip,
   §6.2 PHPStan extensions, §6.3 plugin CI build, §6.4 Dependabot.
6. **Dependencies:** §7.2 / §7.3 in-range updates; majors (swagger-php 7,
   vitest 5, happy-dom 21) one at a time; TypeScript 7 waits for Vue (owner);
   §7.4 plugin packages.
7. **Features:** §9.1 → §9.4 first.

---

## Appendix A — Claims that did not survive verification

Listed so nobody re-chases them.

- "Favorites orphan when a spread is deleted" — **false.** `AdminSpreadController`
  (`deleteSpread`; was `AdminController.php:635` before §4.5) and
  `AccountController::deleteSpread` call `FavoriteSpreadData::removeBySpread()`;
  deck favorites cascade via FK.
- "Admin user list leaks password hashes" — **false.** `Structure/User.php`
  never declares `password_hash`; `UserData::hydrate` drops it.
- "`jsdom` is an unused dependency" — **false.** `markdown.spec.ts` pins
  `@vitest-environment jsdom` for the DOMPurify XSS contract test.

## Appendix B — How the numbers were measured

Re-run these after changes.

```bash
# §1.2 — deprecation repro (from backend/)
php -d error_reporting=E_ALL -d display_errors=1 -d log_errors=0 -r \
  'require "vendor/autoload.php"; $d = new Tarot\Data\DeckData(Tarot\Database\Connection::getInstance()); echo "hydrated ", count($d->retrieve()), " decks\n";' \
  2>&1 | sort | uniq -c | sort -rn | head
```

```bash
# §1.4 — PHP-zone timestamp writes
grep -rn "date('Y-m-d H:i:s')" backend/includes backend/api
# §2.4 — SELECT * in the Data layer
grep -rn "SELECT \*" backend/includes/Tarot/Data
# §4.1 / §4.2 / §4.6 — duplication counts (from frontend/)
grep -rn "padStart(4" src --include=*.vue
grep -rn "fetch('/api" src --include=*.vue --include=*.ts | grep -v __tests__ | wc -l
grep -rhoE "rgba\(201, ?162, ?75" src --include=*.css --include=*.vue | wc -l
```

```bash
# §7 — freshness and advisories
cd frontend && npm outdated
cd backend && composer outdated --direct && composer audit
```

```js
// §3.3 — CSS class census (save as css-usage.mjs, run from frontend/ after `npm run build`)
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
const dist = readdirSync('dist/_app').filter((f) => /^index-.*\.css$/.test(f))[0]
const css = readFileSync(join('dist/_app', dist), 'utf8')
const classes = new Set([...css.matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)].map((m) => m[1]))
function walk(d, out = []) {
  for (const f of readdirSync(d)) {
    const p = join(d, f)
    statSync(p).isDirectory() ? walk(p, out) : /\.(vue|ts|html)$/.test(f) && out.push(p)
  }
  return out
}
const src = walk('src').map((p) => readFileSync(p, 'utf8')).join('\n') + readFileSync('index.html', 'utf8')
let used = 0
const unused = {}
for (const c of classes) {
  if (src.includes(c)) used++
  else unused[c.split('-')[0]] = (unused[c.split('-')[0]] || 0) + 1
}
console.log(`${(css.length / 1024).toFixed(0)} KB, ${classes.size} selectors, ${used} referenced (${((100 * used) / classes.size).toFixed(0)}%)`)
console.log(Object.entries(unused).sort((a, b) => b[1] - a[1]).slice(0, 10))
```

Baseline on 2026-10-27: `407 KB, 780 selectors, 310 referenced (40%)`;
top unused prefixes `is(367) fa(66) has(6) hero(5) panel(5) toast(4)`.
