# AGENTS.md

Operational guide for AI agents and developers working in this repository. Read
this first to understand how the project is laid out, how the pieces fit
together, and the conventions to follow when making changes.

---

## 1. What this project is

**TarotGen.io** (`tarotgen.io`) is a tarot reading generator and reading-sharing
platform. Visitors pick a deck and a spread (or freely choose a card count),
draw cards, then can save and share a permalink to the resulting reading.
Registered users get accounts (readings, custom spreads, favorites), and admins
manage decks, deck systems, spreads, users, and more.

It is a **single-page app (SPA) frontend + REST API backend**:

- **Frontend:** Vue 3 (`<script setup>` + TypeScript), Vue Router, Bulma CSS,
  TipTap (markdown editor), built with Vite.
- **Backend:** PHP 8.5+ (Composer platform pinned to `8.5.6`),
  Slim 4 framework with PHP-DI (autowiring) container, a layered architecture
  over a SQLite database.
- **Database:** SQLite (`db/tarotdb.db`) accessed through PDO, WAL mode.

---

## 2. Tech stack & versions

| Area        | Tooling |
|-------------|---------|
| Frontend    | Vue `^3.5`, vue-router `^4.5`, TypeScript `~6.0` (`vue-tsc ^3.3`), Vite `^8`, Bulma `^1.0` |
| Editor/MD   | TipTap `^3.25` (`@tiptap/*`), `marked`, `dompurify`, `turndown` |
| Icons       | FontAwesome **Pro v7**, self-hosted via the private kit (`@awesome.me/kit-91d12dd2d3`) + `@fortawesome/fontawesome-svg-core`. Needs `.npmrc` to install — see §6. |
| FE testing  | Vitest `^4` with `happy-dom` |
| FE linting  | ESLint `^10` (flat config) + `eslint-plugin-vue` + `@vue/eslint-config-typescript` |
| Backend     | PHP `>=8.5`, Slim 4 (`slim/slim`, `slim/http`, `slim/psr7`), `php-di/slim-bridge` |
| Auth/email  | `lbuchs/webauthn` (passkeys), `phpmailer/phpmailer` (SMTP) |
| BE testing  | PHPUnit `^13` |
| BE linting  | `squizlabs/php_codesniffer` (PSR-12, `phpcs.xml`), `friendsofphp/php-cs-fixer`, PHPStan `^2` (level 6, baselined) |
| Misc tooling| Python scripts for deck image cleanup (`scripts/`) |

---

## 3. Repository layout

The repo is split into **`frontend/`** (Vue/Vite, the npm project root) and
**`backend/`** (PHP, the composer project root). All frontend paths below are under
`frontend/`, all backend paths under `backend/`. The deploy script (`scripts/deploy.ps1`)
flattens both into the live server's single flat web root.

```
/
├── frontend/               # Vue 3 SPA — run npm from here
│   ├── index.html          # SPA entry HTML (Vite).
│   ├── package.json        # Frontend scripts & deps.
│   ├── vite.config.ts      # Vite + Vitest config (alias @ -> src, /api + /assets proxy, manualChunks).
│   ├── eslint.config.js    # ESLint flat config (Vue 3 + TS).
│   ├── tsconfig*.json       # TS project config.
│   ├── .npmrc              # FontAwesome Pro kit token (gitignored).
│   ├── public/             # PWA assets (manifest, sw, icons), robots.txt, sitemap.xml, fonts/.
│   └── src/                # Frontend source (alias `@` -> src).
│       ├── main.ts         #   App bootstrap (mounts after session revalidation).
│       ├── App.vue         #   Root component (nav/layout).
│       ├── fontawesome.ts  #   FontAwesome icon registry — add every icon you use here (§5).
│       ├── constants.ts    #   Shared constants (storage keys, etc.).
│       ├── router/index.ts #   All routes + auth guards.
│       ├── views/          #   Route-level pages (lazy-loaded); views/admin/ for admin.
│       ├── components/     #   Reusable components; components/admin/ for admin.
│       ├── composables/    #   use* composables (state + API access logic).
│       ├── utils/          #   Pure helpers (datetime, markdown, deck, storage...).
│       ├── types/index.ts  #   Shared TypeScript interfaces (mirror API payloads).
│       ├── assets/         #   tokens.css (--myst-* vars), style.css, fonts.css (@font-face).
│       └── __tests__/      #   Vitest specs (*.spec.ts).
│
├── backend/                # PHP API — run composer from here
│   ├── og.php              # Server-rendered Open Graph meta for /reading/{id} shares.
│   ├── dev-router.php      # Local PHP built-in-server router (composer dev); mimics .htaccess.
│   ├── .htaccess           # Web-root rewrites (api/ → og.php → SPA fallback).
│   ├── composer.json       # Backend deps, PSR-4 autoload, `composer test`/`stan`/`dev`.
│   ├── phpunit.xml         # PHPUnit config (tests/Unit suite).
│   ├── phpstan.neon        # PHPStan config (level 6); phpstan-baseline.neon holds pre-existing findings.
│   ├── .env / .env.example # Environment (gitignored / template).
│   ├── api/                # HTTP API entry point (Slim).
│   │   ├── index.php       #   Bootstrap: container, middleware, ALL route definitions.
│   │   ├── dependencies.php #  PHP-DI definitions (only PDO; rest autowired).
│   │   ├── .htaccess       #   Rewrites non-file requests to index.php.
│   │   └── Routes/{Internal,Middleware}/  # Controllers + AbstractController; UserAuth/AdminAuth/OriginGuard.
│   ├── includes/Tarot/     # Domain code (PSR-4 `Tarot\`): Config, Database, Data,
│   │                       #   Repository, Service, Structure, Exception, Utility.
│   ├── tests/Unit/         # PHPUnit tests (namespace `Tarot\Tests\`).
│   ├── scripts/            # PHP/Python CLI utils (make_admin.php, gen_icons.php, indexnow.php, *.py).
│   ├── db/                 # SQLite DB + one-off migration scripts (gitignored).
│   ├── assets/decks/{id}/  # Per-deck card images (Card_XXXX.png, Card_Back.png) (gitignored).
│   └── vendor/             # Composer deps (generated).
│
├── scripts/deploy.ps1      # Build & deploy orchestration (frontend + backend).
├── README.md               # Human-facing quick orientation.
├── .gitignore / .gitattributes
└── (frontend/dist, */node_modules — generated)
```

---

## 4. Backend architecture (the layering rule)

Data flows through clearly separated layers. **Respect these boundaries** — do
not skip layers (e.g. don't run SQL from a controller).

```
HTTP Request
   → Middleware (OriginGuard, UserAuth/AdminAuth)
   → Controller (api/Routes/Internal/*)   — request parsing, HTTP responses
   → Service (includes/Tarot/Service/*)    — business logic (optional layer)
   → Repository (includes/Tarot/Repository)— domain API over the Data layer
   → Data (includes/Tarot/Data/*)          — raw PDO/SQL, hydrates Structures
   → Structure (includes/Tarot/Structure/*)— entity objects returned upward
```

- **Controllers** extend `AbstractController`. Dependencies are **constructor-
  injected** (PHP-DI autowiring resolves concrete classes from type-hints).
  Return Slim responses via `$response->withJson([...], $status)`. Error bodies
  use the shape `['error' => '...']`.
- **Data classes** extend `AbstractData`, which provides `fetchOne()`,
  `fetchAllAs()`, and `applyUpdate()` (dynamic, allow-listed UPDATE). The `PDO`
  handle is **injected**, never fetched from a global, so tests can pass an
  in-memory SQLite PDO. Always use **prepared statements / bound params**.
- **`PDO::class`** is the only manual container definition (see
  `api/dependencies.php`); it comes from `Connection::getInstance()`. Everything
  else (repositories, services, controllers) is autowired.

### Routing
All routes are declared in `api/index.php` (base path `/api`), grouped by
resource. Admin routes are under `/admin` and guarded by `AdminAuth`; account
self-service routes under `/account` are guarded by `UserAuth`. Trailing-slash
variants are allowed via `[/]`.

### Auth & security
- Sessions use PHP sessions hardened in `index.php` (HttpOnly, SameSite=Lax,
  Secure in production). Admin access requires a **user account with the
  `is_admin` flag** — there is no shared admin password. Grant it with
  `php scripts/make_admin.php you@example.com`.
- `OriginGuard` middleware is CSRF defense-in-depth (rejects cross-origin
  state-changing requests). Added last so it runs outermost.
- CORS is **opt-in** via `APP_ORIGIN` (never `*` with credentialed cookies).
- Passkeys/WebAuthn via `lbuchs/webauthn`; optional Google OAuth.
- In production the DI container is compiled to disk and error detail is hidden.

### og.php
The SPA can't set Open Graph tags for crawlers, so `/reading/{id}` is rewritten
to `og.php`, which serves the built `dist/index.html` with reading-specific
meta tags injected (and is careful not to leak password-protected readings).

---

## 5. Frontend architecture

- **Components/views** use Vue 3 `<script setup lang="ts">`. Views are **lazy-
  loaded** in the router so each page (and heavy deps like TipTap) is a separate
  chunk.
- **Composables** (`src/composables/use*.ts`) hold state and API logic. They are
  the primary unit of reusable logic — prefer extending an existing composable
  over scattering `fetch` calls in components.
- **API access:** the framework-free client lives in `composables/apiClient.ts`
  (re-exported from `useApi.ts`, so import either). Pick by how much you need to
  know about failures:
  - `apiFetch<T>()` — returns the data or `null`; for "just give me the list".
  - `apiRequest<T>()` — returns a discriminated `ApiResult<T>` with `ok`,
    `status`, the server's `error` message, the parsed `data` (read structured
    fields like a validation `errors` array), and `networkError`. Use it when a
    caller must tell failures apart or show *why* something failed (`useUser` does).
  - `useAdminApi()` — Vue-aware admin wrapper (auto 401→login, toasts errors).

  All paths are relative to `/api`. The backend returns `{ error: '...' }` on
  failure; `readApiError()`/`messageFromBody()` extract the message. Note
  `apiClient.ts` imports nothing local (so `useUser` can use it without an import
  cycle); the Vue-aware `useAdminApi` stays in `useApi.ts`.
- **Types:** `src/types/index.ts` mirrors API JSON payloads. Keep these in sync
  with backend `Structure`/controller output when changing a response shape.
- **Routing/guards:** `src/router/index.ts`. Route `meta` flags drive guards:
  `admin` (requires `is_admin`), `userOnly` (requires login),
  `userGuest` (redirect away if logged in). Page titles come from `meta.title`.
- **Session bootstrap:** `main.ts` calls `fetchMe()` before mounting so guards
  have user state immediately; the router periodically revalidates.
- **Constants/storage:** keys live in `src/constants.ts` under the `tarot.`
  namespace — don't hardcode storage keys.
- **Icons:** FontAwesome is self-hosted and registered in `src/fontawesome.ts`.
  Only the icons actually used are imported/added to the `library`, then
  `dom.watch()` converts `<i class="fa-...">` tags to SVG (and watches for ones
  Vue renders later). **Footgun:** if you use a new `fa-*` class in a template
  without adding its import here, it silently won't render — always register it.
  Icons come from the private kit, so there are no CDN requests.
- **Styling:** Bulma utility classes + CSS custom properties (`--myst-*`) in
  `src/assets/tokens.css`. Theme is dark (`data-theme="dark"` on `<html>`).
- **Heading fonts:** headings read the `--myst-heading-font` CSS var; the
  `useHeadingFont` composable swaps it and persists the choice. `FontSwitcher.vue`
  is a temporary audition tool and is **currently disabled** (commented out in
  `App.vue`); `initHeadingFont()` in `main.ts` still applies any saved choice.
  Candidate faces live in `public/fonts/` + `src/assets/fonts.css`.

---

## 6. Build, run, and test commands

Run from the project root. Shell is **PowerShell** on Windows; chain with `;`.

### Frontend (npm) — run from `frontend/`
```powershell
cd frontend
npm install            # install deps (requires frontend/.npmrc with the FA Pro kit token — see below)
npm run dev            # Vite dev server :5173 (proxies /api and /assets to localhost:80)
npm run build          # production build to frontend/dist/
npm run preview        # preview the production build
npm run type-check     # vue-tsc --noEmit (strict TS check)
npm run lint           # eslint . (flat config; lints src/ TS + Vue SFCs)
npm run lint:fix       # eslint . --fix
npm test               # vitest run (single pass)
npm run test:watch     # vitest watch mode
```

> **`frontend/.npmrc` is required to `npm install`.** The icon packages come from a
> private FontAwesome registry, so install needs a local `frontend/.npmrc` (gitignored)
> holding the kit auth token. Without it, `@awesome.me/kit-*` and
> `@fortawesome/*` won't resolve.

### Backend (composer / php) — run from `backend/`
```powershell
cd backend
composer install       # install PHP deps
composer dev           # PHP built-in server on :80 via dev-router.php (the API backend)
composer test          # run PHPUnit (auto-discovers backend/phpunit.xml)
composer stan          # PHPStan static analysis (backend/phpstan.neon, level 6, --memory-limit=512M baked in)
composer lint          # PHP_CodeSniffer (alias for `phpcs`)
composer lint:fix      # php-cs-fixer fix (auto-fix code style)
vendor\bin\phpunit     # run PHPUnit directly
```

> PHPStan runs at **level 6** with a baseline (`phpstan-baseline.neon`) capturing
> a handful of pre-existing findings, so `composer stan` fails only on *new* issues.
> The Data/Repository/Structure layers and controller route `$args` are fully
> annotated (`array<string,mixed>`, `list<Structure>`, `array<string,string>`,
> etc.), so keep new array params/returns typed. Regenerate the baseline with
> `composer stan -- --generate-baseline`; raise toward `max` incrementally.

To serve the full app locally, run **two processes in two terminals**: from
`backend/` run `composer dev` (the PHP backend on port 80) and from `frontend/` run
`npm run dev` (the Vite SPA on :5173). The Vite proxy forwards `/api` and `/assets`
to `http://localhost:80`, where `dev-router.php` reproduces the production
`.htaccess` routing (`/api/*` → `api/index.php`, files under `/assets/*` served from
disk) for PHP's built-in server, which has no `.htaccess`. Any PHP host serving
`/api` on port 80 (e.g. Apache) works too.

> **Gotcha — don't `import` files under `/assets` in Vue.** `/assets` is proxied
> to the PHP host, so referencing e.g. the brand logo as `<img src="/assets/...">`
> would make `@vitejs/plugin-vue` compile it into an asset-module import that
> returns raw image bytes (not JS) and blanks the app. Bind it as a runtime
> string instead (`const brandLogo = '/assets/favicon.png'`; `:src="brandLogo"`).

### Always do after changes
- After editing **frontend** TS/Vue: run `npm run type-check`, `npm run lint`, and
  the relevant `npm test` specs.
- After editing **backend** PHP: run `composer test`, `composer stan`, and
  `composer lint` for style.

### Deployment
The site runs on a DigitalOcean droplet (Apache + PHP 8.5) at
`/var/www/tarotgen.io`, a single **flat** web root. `scripts/deploy.ps1` flattens
`frontend/dist` and the `backend/` runtime files into it (PowerShell; uses PuTTY
`pscp`/`plink` with the DigitalOcean `.ppk` key):

```powershell
.\scripts\deploy.ps1                    # frontend (default): npm build + atomic dist swap
.\scripts\deploy.ps1 -Target backend    # PHP code; runs composer install on the droplet if composer.lock changed
.\scripts\deploy.ps1 -Target both       # frontend, then backend
.\scripts\deploy.ps1 -SkipBuild         # deploy existing dist/ without rebuilding
```

The script flattens the repo into the server layout and **never** uploads the
server-side `db/`, `.env`, or `assets/decks/`. Frontend keeps a `dist.old` rollback;
backend archives the previous code to `.deploy/backend-prev.tgz`. It keeps each
target to ≤3 SSH connections (the droplet runs `ufw limit ssh`).

---

## 7. Database

- Single SQLite file: `db/tarotdb.db`. Accessed via `Tarot\Database\Connection`
  (PDO singleton). Foreign keys are enforced (`PRAGMA foreign_keys = ON`), WAL
  journaling is enabled, and `busy_timeout` is set.
- **Schema changes** are applied via the one-off scripts in `db/`
  (`migrate_*.php`, `add_*`, `drop_*`). They are plain PHP run from the CLI:
  `php db/migrate_xxx.php`. There is no automated migration framework — add a new
  `migrate_*.php` script following the existing pattern when you change schema.
- `php db/check_state.php` prints current tables/schema for inspection.
- The DB path resolves relative to `Connection.php` (`<root>/db/tarotdb.db`), so
  scripts work regardless of the working directory.
- **Rebuilding the schema:** the committed `db/schema.sql` recreates the full
  structure — `sqlite3 db/tarotdb.db < db/schema.sql`. Regenerate it after a
  migration with `php scripts/dump_schema.php` so the committed schema stays in
  sync. The live `tarotdb.db` is gitignored and remains the source of truth for
  *data*; the `migrate_*.php` scripts are unordered one-offs that assume earlier
  state, so still treat the DB file as precious and back it up before migrating.

---

## 8. Testing conventions

- **Frontend (Vitest):** specs live in `src/__tests__/*.spec.ts`. Config in
  `vite.config.ts` (`environment: happy-dom`, `restoreMocks`, `unstubGlobals`,
  setup file `vitest.setup.ts`). Test composables and utils as units.
- **Backend (PHPUnit):** tests in `tests/Unit/` under namespace
  `Tarot\Tests\`. Config (`phpunit.xml`) treats warnings/deprecations/notices as
  failures (`failOnWarning/Deprecation/Notice`) — keep tests clean. Service-layer
  tests pass an **in-memory SQLite PDO** to Data classes for isolation.
- Add/extend tests when you change logic in services, repositories, composables,
  or utils.

---

## 9. Configuration / environment

- Copy `.env.example` to `.env` and fill in values. Loaded by `Tarot\Config\Env`
  in `api/index.php`.
- Separately, a gitignored **`.npmrc`** at the repo root must hold the FontAwesome
  Pro kit auth token for `npm install` to resolve the icon packages (see §6).
- Key vars: `APP_ENV` (`development`/`production`), `APP_ORIGIN` (CORS),
  `APP_URL` (links in emails), `SMTP_*` / `MAIL_*` (transactional email),
  `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI` (optional OAuth).
- When `APP_ENV=production`: detailed errors are hidden, session cookies get the
  Secure flag, and the DI container is compiled. Never leak stack traces in prod.
- When SMTP is unconfigured outside production, the activation link is returned
  in the API response so sign-up still works in dev.

---

## 10. Conventions & expectations for changes

- **Match existing patterns.** New backend resource? Add a `Structure`, `Data`,
  `Repository`, optional `Service`, a `Controller` extending
  `AbstractController`, and route group in `api/index.php`. New frontend feature?
  Add a `use*` composable + view/component and a route with appropriate `meta`.
- **Layer discipline:** controllers don't touch SQL; SQL lives only in `Data`.
- **Security first:** always use prepared statements; never trust client input;
  keep error responses as `{ error }`; preserve auth guards on protected routes.
- **Keep types in sync** between PHP responses and `src/types/index.ts`.
- **Comments** in this codebase explain *why*, not *what* — follow that style.
- **PSR-4 / PSR-12** for PHP (run php-cs-fixer/phpcs). **Strict TypeScript**
  (`strict: true`) — keep `npm run type-check` clean.
- Don't commit generated artifacts (`dist/`, `vendor/`, `node_modules/`,
  `.phpunit.cache`) or the local `.env`.
- Deck card images live at `assets/decks/{deck_id}/Card_XXXX.png` with a
  `Card_Back.png`; thumbnails are generated via `ThumbnailService` / admin routes.

---

## 11. Quick reference: where things live

| I want to…                          | Go to |
|-------------------------------------|-------|
| Add/modify an API endpoint          | `api/index.php` (route) + `api/Routes/Internal/*Controller.php` |
| Change DB queries                   | `includes/Tarot/Data/*Data.php` |
| Add business logic                  | `includes/Tarot/Service/*Service.php` |
| Add an entity shape (PHP)           | `includes/Tarot/Structure/*.php` |
| Add a frontend page                 | `src/views/*.vue` + route in `src/router/index.ts` |
| Add reusable frontend logic         | `src/composables/use*.ts` |
| Change shared API types             | `src/types/index.ts` |
| Use a new icon                      | register it in `src/fontawesome.ts` (import + `library.add`) |
| Change/add a heading font           | `public/fonts/` + `src/assets/fonts.css` + `useHeadingFont.ts` |
| Change a DB schema                  | new `db/migrate_*.php` script |
| Grant admin                         | `php scripts/make_admin.php <email>` |
| Adjust auth/session/CSRF            | `api/index.php` + `api/Routes/Middleware/*` |

