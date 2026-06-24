# TarotGen.io

A tarot reading generator and reading-sharing platform. Pick a deck and a spread
(or freely choose a card count), draw cards, then save and share a permalink to the
result. Registered users get accounts (saved readings, custom spreads); admins
manage decks, spreads, users, and more.

It's a **Vue 3 SPA frontend + PHP REST API backend**, served from a single web
root behind Apache (the SPA is the static `dist/` build; `/api/*` hits the PHP app;
`/reading/{id}` is server-rendered by `og.php` for social link previews).

> **For contributors and AI agents:** [`AGENTS.md`](AGENTS.md) is the deep guide —
> architecture, the backend layering rule, conventions, and gotchas. This README is
> the quick orientation.

## Where things live

| Path | What |
|------|------|
| `src/` | Frontend: Vue 3 (`<script setup>` + TS), Vue Router, Bulma, TipTap. Views, components, composables, utils, types. |
| `index.html` | SPA entry HTML (Vite). `og.php` swaps meta tags for crawlers. |
| `api/` | PHP API entry (Slim 4). `index.php` defines all routes; `Routes/` holds controllers + middleware. |
| `includes/Tarot/` | Backend domain code (PSR-4 `Tarot\`): Data → Repository → Service → Structure layers, plus Config/Database/Utility. |
| `og.php` | Server-rendered Open Graph meta for `/reading/{id}` shares. |
| `tests/Unit/` | PHPUnit tests. Frontend specs live in `src/__tests__/`. |
| `config/` | PHP QA tool config: `phpunit.xml`, `phpstan.neon`, `phpstan-baseline.neon`. |
| `scripts/` | CLI utilities (`make_admin.php`, `indexnow.php`, …) and **`deploy.ps1`**. |
| `public/` | PWA assets (manifest, service worker, icons), `robots.txt`, `sitemap.xml`, self-hosted fonts. |
| `db/`, `assets/decks/` | SQLite DB and per-deck card images — server-side data, gitignored, **never** in a deploy payload. |
| `dist/`, `vendor/`, `node_modules/` | Generated build / dependency output. |

> **Why most config files stay at the repo root:** `package.json`, `composer.json`,
> `vite.config.ts`, `tsconfig*.json`, `eslint.config.js`, and `.htaccess` are
> auto-discovered by their tools and editors at the project root — relocating them
> only adds CLI flags or breaks IDE integration. Only the PHP QA configs, which run
> exclusively via `composer` scripts, live under `config/`.

## Quickstart (local dev)

Requires Node, PHP 8.5+, Composer, and a gitignored `.npmrc` with the FontAwesome
Pro kit token (see `AGENTS.md` §6). Copy `.env.example` → `.env`.

```powershell
npm install ; composer install      # install deps

# Run the app with TWO processes:
npm run dev:api                     # PHP backend on :80 (via dev-router.php)
npm run dev                         # Vite SPA on :5173 (proxies /api + /assets to :80)
```

## Checks

```powershell
npm run type-check ; npm run lint ; npm test    # frontend
composer test ; composer stan ; composer lint   # backend (configs read from config/)
```

## Build & deploy

```powershell
npm run build                       # production SPA build → dist/

.\scripts\deploy.ps1                # build + deploy frontend (atomic dist swap)
.\scripts\deploy.ps1 -Target backend   # deploy PHP code; composer install on the droplet if deps changed
.\scripts\deploy.ps1 -Target both       # frontend, then backend
.\scripts\deploy.ps1 -SkipBuild     # deploy the existing dist/ without rebuilding
```

The deploy script flattens this repo into the live server layout at
`/var/www/tarotgen.io` and **never touches** the server's `db/`, `.env`, or
`assets/`. Each target keeps a rollback copy (`dist.old` / `.deploy/backend-prev.tgz`);
run `.\scripts\deploy.ps1 -?` for full parameter help.
