# TarotGen.io

A tarot reading generator and reading-sharing platform. Pick a deck and a spread
(or freely choose a card count), draw cards, then save and share a permalink to the
result. Registered users get accounts (saved readings, custom spreads); admins
manage decks, spreads, users, and more.

It's a **Vue 3 SPA frontend + PHP REST API backend**, split into `frontend/` and
`backend/`. In production both are served from a single flat web root behind Apache
(the SPA is the static `dist/` build; `/api/*` hits the PHP app; `/reading/{id}` is
server-rendered by `og.php` for social link previews). The deploy script flattens
the two folders into that web root.

> **For contributors and AI agents:** [`AGENTS.md`](AGENTS.md) is the deep guide —
> architecture, the backend layering rule, conventions, and gotchas. This README is
> the quick orientation.

## Where things live

```
frontend/   Vue 3 SPA — npm project root
backend/    PHP API — composer project root
scripts/    deploy.ps1 (repo-level orchestration)
```

| Path | What |
|------|------|
| `frontend/src/` | Vue 3 (`<script setup>` + TS), Vue Router, Bulma, TipTap. Views, components, composables, utils, types. Specs in `src/__tests__/`. |
| `frontend/index.html` | SPA entry HTML (Vite). |
| `frontend/public/` | PWA assets (manifest, service worker, icons), `robots.txt`, `sitemap.xml`, self-hosted fonts. |
| `backend/api/` | PHP API entry (Slim 4). `index.php` defines all routes; `Routes/` holds controllers + middleware. |
| `backend/includes/Tarot/` | Domain code (PSR-4 `Tarot\`): Data → Repository → Service → Structure, plus Config/Database/Utility. |
| `backend/og.php` | Server-rendered Open Graph meta for `/reading/{id}` shares. |
| `backend/tests/Unit/` | PHPUnit tests. |
| `backend/scripts/` | PHP/Python CLI utilities (`make_admin.php`, `indexnow.php`, …). |
| `backend/{phpunit.xml,phpstan.neon}` | QA configs (auto-discovered when running `composer` from `backend/`). |
| `backend/{db,assets/decks}/` | SQLite DB and per-deck card images — server-side data, gitignored, **never** in a deploy payload. |
| `scripts/deploy.ps1` | Build & deploy orchestration (frontend + backend). |
| `*/{node_modules,vendor,dist}/` | Generated dependency / build output. |

## Quickstart (local dev)

Requires Node 20.19+ (or 22.12+, as Vite 8 needs), PHP 8.5+, Composer, and a
gitignored `frontend/.npmrc` with the FontAwesome Pro kit token (see `AGENTS.md`
§6). Copy `backend/.env.example` → `backend/.env`. Run the app as **two
processes, one per folder**:

```powershell
cd frontend ; npm install            # frontend deps
cd ..\backend ; composer install     # backend deps

# Terminal 1 — PHP backend on :80
cd backend ; composer dev            # = php -S localhost:80 dev-router.php

# Terminal 2 — Vite SPA on :5173 (proxies /api + /assets to :80)
cd frontend ; npm run dev
```

## Checks

```powershell
cd frontend ; npm run type-check ; npm run lint ; npm test
cd backend  ; composer test ; composer stan ; composer lint
```

## Build & deploy

```powershell
cd frontend ; npm run build              # production SPA build → frontend/dist/

.\scripts\deploy.ps1                     # build + deploy frontend (atomic dist swap)
.\scripts\deploy.ps1 -Target backend     # deploy PHP code; composer install on the droplet if deps changed
.\scripts\deploy.ps1 -Target both        # frontend, then backend
.\scripts\deploy.ps1 -SkipBuild          # deploy the existing frontend/dist without rebuilding
```

The deploy script flattens `frontend/` + `backend/` into the live server layout at
`/var/www/tarotgen.io` and **never touches** the server's `db/`, `.env`, or
`assets/`. Each target keeps a rollback copy (`dist.old` / `.deploy/backend-prev.tgz`);
run `.\scripts\deploy.ps1 -?` for full parameter help.
