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
     regenerate it with `scripts/dump_schema.php` — the local DB has drifted
     (it still has the legacy deck columns and is missing `plugin_client_identities`),
     so a wholesale regen silently changes unrelated tables and reorders the file.
  2. `backend/db/migrate_*.php` scripts are **gitignored**; `scripts/deploy.ps1`
     never uploads `db/`. Every migration must be copied to the droplet's
     `<WebRoot>/db/` and run there *before* deploying backend code that needs it.
  3. After route/schema changes: `composer docs` → commit `backend/openapi.json`
     → `npm run gen:types`. `OpenApiSpecTest` and CI both fail on drift.
- Product constraint: **this site does not interpret readings.** No card
  meanings, no AI readings, no "what does this spread say". It is a tool for
  people who do readings. Do not propose or build that.

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

## 0. State of the tree (read before doing anything)

- [ ] **Uncommitted feature work is sitting in the tree.** Card-report reasons
      (22 modified + 7 new files: `CardReportReason.php`, `CardReportSubmission.php`,
      `CardReportDialog.vue`, `useCardReports.ts`, `utils/cardReports.ts`, two specs).
      All gates were green when it was written. Commit it (or stash it) before
      starting audit work so diffs stay reviewable. It needs
      `php db/migrate_card_report_submissions.php` run on the droplet **before**
      the next backend deploy.
- [ ] **Three dead worktrees + branches.** `.claude/worktrees/{dazzling-lovelace-4f59b9,
      pensive-tereshkova-d135ec,priceless-jackson-d01aeb}` and branches
      `claude/dazzling-lovelace-4f59b9`, `claude/pensive-tereshkova-d135ec`,
      `claude/priceless-jackson-d01aeb` have **zero commits** ahead of `main`.
      They were spun off for the session-cookie warning, the stale relay docs,
      and the dark `color-scheme`; none of that work landed, so all three items
      are still open below (§1.3, §5.4, §5.8). `git worktree remove` + `git branch -D`.
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
five-entry baseline; strict TS + typed ESLint.

---

## 1. Bugs and risks

- [ ] **1.0 The production database and `.env` were publicly downloadable.** [critical] [S + M]
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

- [ ] **1.1 No database backups exist anywhere.** [critical] [S]
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

- [ ] **1.2 Dev deck loads emit dynamic-property deprecations; `schema.sql` is stale.** [low] [S]
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

- [ ] **1.3 PHP warning on every login/logout.** [medium] [S]
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

- [ ] **1.4 Timezone coupling between PHP and SQLite timestamps.** [low] [S]
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

- [ ] **1.5 CSP has been "report-only, promote later" since it shipped.** [medium] [S–M]
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

- [ ] **1.6 Reading-id collision is a 500.** [low] [S]
  - **Where:** `backend/includes/Tarot/Service/ReadingService.php:435` — 5 random
    bytes (40 bits), no retry; a UNIQUE violation surfaces as a PDOException.
  - **Fix:** in `persist()`, catch the constraint violation (SQLSTATE 23000),
    regenerate, retry up to 3 times.
  - **Verify:** unit test with a `ReadingRepository` double that throws once.

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

- [ ] **1.8 Small leaks / decisions to record.** [low] [S]
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

---

## 2. Database

- [ ] **2.1 Four dead tables and six dead indexes from the removed share relay.** [medium] [S]
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

- [ ] **2.2 Legacy deck columns** — handled by §1.2 (drop them, stop selecting `*`).

- [ ] **2.3 `readings` has no index on `reading_time`.** [low] [S]
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

- [ ] **2.4 `SELECT *` + `FETCH_CLASS` couples schema to Structures.** [medium] [S]
  - **Where:** 18 occurrences across `ChangelogData`, `DeckSystemData` (×6),
    `SpecialCardData` (×3), `UserData` (×4), plus `DeckData`'s `d.*`. Any added
    column that a Structure doesn't declare becomes a dynamic property →
    deprecation now, fatal in PHP 9. §1.2 is this bug happening.
  - **Fix:** explicit column lists (a `private const string COLS` per Data
    class, as `ReadingData::SELECT_COLS` already does).
  - **Verify:** `grep -rn "SELECT \*" backend/includes/Tarot/Data` is empty.
    Note the unit tests cannot catch prod schema drift because each test creates
    its own minimal table; the explicit list is the real guard.

- [ ] **2.5 Two hydration styles.** [low] [M]
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

- [ ] **2.6 `myReadings` is unbounded.** [medium] [M]
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

- [ ] **2.7 Cosmetic / when-you-touch-it.** [low]
  - `special_cards` FK has no `ON DELETE CASCADE` (worked around manually in
    `DeckData::delete`, line 125) and its constraint is named
    `FK__sepcial_cards__decks`. SQLite needs a table rebuild to fix; do it when
    the table is next migrated.
  - Mixed timestamp sources — §1.4.
  - No `ANALYZE`/`PRAGMA optimize` ever runs; fold into the retention cron (§1.7).

---

## 3. Performance and bandwidth

- [ ] **3.1 Card images are served with no cache headers.** [high] [S]
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

- [ ] **3.2 Lightbox serves raw PNG; only thumbnails are WebP.** [high] [M]
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

- [ ] **3.3 The CSS bundle is 407 KB (48.6 KB gz) and roughly 60% unreferenced.** [medium] [M]
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

- [ ] **3.4 `og.php` runs for every human visit to `/reading/{id}`.** [low] [M]
  - **Where:** `.htaccess` rewrite; `og.php` does PHP + 2 queries + regex over
    the shell per view and makes those pages edge-uncacheable. Fine at today's
    scale; it is the first thing to revisit if reading views climb. Options:
    rewrite only for crawler user agents, or a 60-second per-id micro-cache.

- [ ] **3.5 Eager thumbnails.** [low] [S]
  - Only two `loading="lazy"` attributes exist (both `ReadingView.vue`). Add to
    card thumbnails in `AccountReadingsView`, the `CustomReadingView` picker,
    and admin tables.

---

## 4. Duplication

- [ ] **4.1 Card image URL building is copied four times.** [medium] [S]
  - **Where:** `'Card_' + String(id).padStart(4, '0')` + `/assets/decks/{deck}/…`
    in `views/ReadingView.vue:83` (+ back-card literals at 66, 71, 81),
    `views/CustomReadingView.vue:200`, `views/FreeDrawPlacementView.vue:134`,
    `views/admin/ErroredCardsView.vue:76`.
  - **Fix:** `src/utils/cardImages.ts` exporting `cardImageUrls(deckId, cardId)`
    → `{ full, thumb }` and `cardBackUrls(deckId)`; the version token from §3.1
    lives here too. Unit-test it.
  - **Verify:** `grep -rn "padStart(4" src --include=*.vue` returns nothing.

- [ ] **4.2 Forty raw `fetch('/api' + …)` call sites bypass the API client.** [medium] [M]
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

- [ ] **4.3 The admin list-page scaffold is repeated in nine views.** [low] [M]
  - **Where:** back link + `PageHeader` + search field + `.settings-panel` +
    table + empty-state in `views/admin/{Changelog,Contacts,Decks,DeckSystems,
    ErroredCards,Readings,SpecialCards,Spreads,Users}View.vue`.
  - **Fix:** `components/admin/AdminListPage.vue` with `title`/`subtitle`/
    `searchPlaceholder` props and `actions`/`default`/`empty` slots; views keep
    their table bodies.
  - **Verify:** each page renders identically (screenshots), line count drops.

- [ ] **4.4 Two type systems, one of them dead.** [medium] [M]
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

- [ ] **4.5 `AdminController` is 1,505 lines / 45 methods.** [medium] [M]
  - **Fix:** split per resource to mirror the admin views and the route groups
    already in `api/index.php:252-318`: `AdminDashboardController`,
    `AdminDeckController` (+ special cards, thumbnails), `AdminDeckSystemController`,
    `AdminSpreadController` (+ pending), `AdminReadingController`,
    `AdminUserController`, `AdminContactController`, `AdminChangelogController`,
    `AdminCardReportController`. Mechanical; keep the `OA\Tag`s so the spec
    doesn't move.
  - **Verify:** `composer docs` produces an identical `openapi.json`
    (operationIds are hash-based — expect them to change; paths/schemas must not).

- [ ] **4.6 The gold tint is typed out 39 times.** [medium] [S]
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

- [ ] **4.7 Card-report reason copy lives in three places by design** (frontend
  `utils/cardReports.ts`, backend `CardReportReason`, plugin `ApiModels.cs`).
  [low] [S] Add a Vitest that reads `backend/openapi.json` and asserts the
  frontend keys equal the `CardReportReason` enum so they cannot drift.

---

## 5. Standards and consistency

- [ ] **5.1 `composer.json` has `"*"` version constraints** on `slim/slim`,
  `slim/http`, `slim/psr7`, `php-di/slim-bridge`, `friendsofphp/php-cs-fixer`,
  `squizlabs/php_codesniffer`. [medium] [S] Pin to the installed majors
  (`composer show -D` for current versions) with caret ranges.

- [ ] **5.2 php-cs-fixer has no config file.** [medium] [S]
  `composer lint` runs phpcs (PSR-12, warnings ignored); `composer lint:fix`
  runs php-cs-fixer with **no** `.php-cs-fixer.dist.php`, so the fixer and the
  checker don't agree on a ruleset. Pick one: recommended php-cs-fixer with a
  dist config (`@PSR12` + `@PHP8x` risky set as tolerated), `php-cs-fixer check`
  in CI, and drop phpcs; or keep phpcs and remove the fixer.

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

- [ ] **5.5 `contactForm.spec.ts` tests a re-implementation, not the code.** [low] [S]
  The spec defines its own `submitContact()` with its own fetch logic and tests
  that. It cannot fail when `ContactView.vue` changes. Replace with a test of
  the composable §4.2 introduces, or a `@vue/test-utils` mount.

- [ ] **5.6 Accessibility basics.** [low] [S] No `<main>` landmark anywhere in
  `src/`, no skip link. Add both in `App.vue`; add
  `eslint-plugin-vuejs-accessibility` (§6.1). Reka already covers the widgets.

- [ ] **5.7 Error-swallowing composables.** [low] [S] `useDecks.ts:32` and
  friends (`useSpreads`, `useFavorite*`) catch and drop failures. Return an
  `ApiResult` or toast; §4.2 is the natural moment.

- [ ] **5.8 Native controls render light on the dark theme.** [low] [S] No
  `color-scheme` is declared anywhere (`getComputedStyle(html).colorScheme`
  is `normal`), so scrollbars inside tall modals and native form chrome are
  white-on-charcoal. Add `color-scheme: dark` at `:root` in `tokens.css` and a
  restrained `scrollbar-color` using existing tokens. (This was one of the three
  tasks that never landed.)

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

- [ ] **6.2 Backend.** [medium] [S–M]
  - PHPStan extensions via `phpstan/extension-installer`: `phpstan-strict-rules`,
    `phpstan-deprecation-rules` (would have caught §1.2 at analysis time if the
    Structures were analysed against real rows — at minimum it flags deprecated
    API use), `phpstan-phpunit`.
  - **Rector** (`rector/rector`) with the PHP 8.5 level set + dead-code set,
    `--dry-run` in CI.
  - `composer-unused` + `composer-require-checker` (implicit deps, e.g. `ext-gd`
    for `ThumbnailService`, `ext-mbstring`, `ext-json` are not declared).
  - `ergebnis/composer-normalize`.
  - **`composer audit` as a CI step** — it is red today (§7).
  - Coverage with `pcov` in CI.

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

- [ ] **7.1 `composer audit` fails: HIGH CVE-2026-109413 in `symfony/yaml`**
  (transitive via `friendsofphp/php-cs-fixer`, dev-only, but red). [high] [S]
  `composer update friendsofphp/php-cs-fixer symfony/yaml` and re-run.
- [ ] **7.2 Composer, direct:** `zircote/swagger-php` 6.6.0 → 6.20.0 (14 minors
  behind — regenerate and diff `openapi.json` after), `phpstan/phpstan`
  2.2.8 → 2.3.3, `phpunit/phpunit` 13.3.1 → 13.4.5, `php-cs-fixer`
  3.95.20 → 3.95.27, `slim/slim` 4.15.2 → 4.15.3. [low] [S]
- [ ] **7.3 npm:** 20 packages behind. Minors: `vue` 3.5.44, `vue-router` 5.4.0,
  `vite` 8.3.5, `reka-ui` 2.11.0, `@tiptap/*` 3.32.0, `dompurify` 3.4.17,
  `marked` 18.1.2, `eslint` 10.13.0, `vue-tsc` 3.3.13, `@vitejs/plugin-vue`
  6.0.10, `sass-embedded` 1.106.1, `jsdom` 30.1.3, `prettier` 3.9.10.
  **Majors (plan separately):** `typescript` 6.0.3 → 7.0.2, `vitest`
  4.1.11 → 5.0.5, `happy-dom` 20.11.2 → 21.0.4. [low–medium] [S–M]
- [ ] **7.4 Plugin:** `Dalamud.NET.Sdk` 15.0.0, `System.Security.Cryptography.ProtectedData`
  10.0.11 — **unverified** (NuGet was not reachable from the audit sandbox).
  `dotnet list package --outdated` from a networked shell.

---

## 8. Tests

Current: backend 240 tests / 638 assertions (unit only); frontend 132 tests
across 17 specs (utils + composables only). Coverage is not measured anywhere
(`coverage: none` in CI).

- [ ] **8.1 No HTTP-level tests at all.** [high] [M]
  All 14 controllers and all three auth middleware (`UserAuth`, `AdminAuth`,
  `AccountAuth`) are untested — `AdminController`'s 1,505 lines included.
  **Fix:** extract app construction from `api/index.php` into `api/app.php`
  (returns `$app`; `index.php` becomes `require 'app.php'; $app->run();`), then
  a PHPUnit base case that builds the app against an in-memory SQLite seeded
  from `db/schema.sql` and dispatches PSR-7 requests. First targets: 401 shapes
  from each middleware, `OriginGuard` end-to-end, validation 400s, card-reports
  201/200/400/429, one admin CRUD round-trip.
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
- [ ] **8.5 Replace `contactForm.spec.ts`** (§5.5). [low] [S]
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
  `POST /admin/decks/{id}/thumbnails`, so the reporting loop closes in one click.
  [low] [S]
- [ ] **9.12 PWA offline for saved readings.** [low] [L] `sw.js` is a
  pass-through today (correctly, to avoid stale deploys); a reading-scoped cache
  is a later project.

---

## 10. Suggested order

Revised after the prod reconnaissance (§1.2/§1.3/§1.4 turned out latent or
dev-only; §3.1/§3.2 turned out to be the largest user-visible cost):

1. §0 (owner commits the card-report work; prod migration
   `migrate_card_report_submissions.php` before the next backend deploy) →
   §1.1 backups → §3.1 cache headers → §3.2 full-size WebP → §7.1 audit fix →
   §1.3 session warning → §1.2 + §2.4 explicit columns / `schema.sql` decks fix.
2. §1.7 retention (privacy text first) → §2.1 dead schema → §1.4 timezone →
   §1.5 CSP → §2.3 index.
3. §4.1 / §4.2 / §4.6 with §6.1 (Stylelint + Knip) landing alongside so the
   duplication can't grow back; §5.1 / §5.2 / §5.4 docs.
4. §8.1 HTTP tests (unlocked by the `app.php` split) and §8.3 component tests;
   §6.3 plugin CI.
5. Features, §9.1 → §9.4 first.

---

## Appendix A — Claims that did not survive verification

Listed so nobody re-chases them.

- "Favorites orphan when a spread is deleted" — **false.** `AdminController.php:635`
  and `AccountController.php:393` call `FavoriteSpreadData::removeBySpread()`;
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
