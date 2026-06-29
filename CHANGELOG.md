# Changelog

All notable changes to **TarotGen.io** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Note:** This project predates formal release tagging, so the entries below
> were reconstructed from the Git history. Dates reflect the day the work landed
> on the development branch. The application version is sourced from
> [`frontend/package.json`](frontend/package.json) and is shown on the admin
> dashboard.

## [Unreleased]

Nothing yet.

## [3.3.0] — 2026-06-28

Bot/abuse protection for the sign-in flow.

### Added

- **Cloudflare Turnstile on the login form.** A privacy-friendly CAPTCHA now
  guards password sign-in.
  - New `Tarot\Service\TurnstileService` performs server-side token
    verification against Cloudflare's `siteverify` endpoint, **failing closed**
    (a challenge that cannot be verified is treated as not passed).
  - New public `GET /api/config` endpoint (`ConfigController`) exposes the
    non-secret Turnstile **site key** to the SPA, keeping the backend `.env` the
    single source of truth. The secret is never sent to the client.
  - New self-configuring `TurnstileWidget.vue` component: it fetches the site
    key, lazy-loads the Cloudflare script once, renders the widget, and supports
    `v-model` (token) + `v-model:enabled` with a `reset()` method for retries.
  - The login form disables submission until the challenge is solved and resets
    the single-use token after a failed attempt.
- Configuration via two new environment variables, documented in
  [`backend/.env.example`](backend/.env.example):
  - `CLOUDFLARE_TURNSTILE_SITEKEY`
  - `CLOUDFLARE_TURNSTILE_SECRET`
- Unit coverage for `TurnstileService` (configuration detection plus
  pass/reject/transport-error verification paths).

- The application version (from `package.json`) is now shown on the admin
  dashboard header.

### Changed

- `POST /api/user/login` now requires a valid `turnstile_token` **before**
  credentials are checked — but only when Turnstile is configured. A failed
  challenge is counted against the existing per-IP login rate limiter.

### Fixed

- **Admin panel no longer crashes for signed-in admins.** The navbar had a
  "Cards" link pointing at a non-existent `admin-cards` route; the unresolvable
  `<RouterLink>` threw during render and blanked the whole SPA for any logged-in
  admin. Removed the orphaned link.
- Added a global Vue `errorHandler` so a single failing component degrades
  gracefully (logged) instead of taking down the entire app on mount.

### Security

- Adds a human-verification layer in front of credential stuffing and automated
  login attempts, complementing the existing IP rate limiting and
  `OriginGuard` CSRF defenses.

### Developer experience

- Added project governance docs: this `CHANGELOG.md`, a `CONTRIBUTING.md`
  guide, and a proprietary `LICENSE`.
- Added a **GitHub Actions CI** pipeline (`.github/workflows/ci.yml`) that runs
  the full check suite on every push and pull request: frontend
  (type-check, lint, unit tests, build) and backend (PHPUnit, PHPStan, and
  PHPCS).
- Wired up **PHPCS to the PSR-12 standard** (`backend/phpcs.xml`) and applied
  the auto-fixes across the backend so `composer lint` passes.

### Notes

- **Graceful degradation:** with no keys set, `/api/config` returns a `null`
  site key, the widget stays hidden, and login proceeds unchallenged — so local
  development without keys is unaffected.

## [3.2.0] — 2026-06-24

Repository restructure and tooling hygiene. No user-facing behavior changes.

### Changed

- **Split the codebase into a `frontend/` + `backend/` monorepo.** Vue/Vite
  (npm) now lives under `frontend/`; the Slim/PHP (Composer) app lives under
  `backend/`. Every PHP path is `__DIR__`-relative within the backend subtree,
  so the move required no code edits. The production deploy
  (`scripts/deploy.ps1`) flattens both trees back into the single web root.
- Normalized all line endings to **LF** and added a `.gitattributes` to enforce
  it, ending cross-platform CRLF churn.

### Fixed

- A batch of accumulated refinements across the SPA views and admin screens
  (consistency, edge-case handling, and input-validation hardening), backed by
  new `Input` and `IndexNow` unit tests.

## [3.1.0] — 2026-06-11

Richer readings and a professional icon system.

### Added

- **Additional draws within a reading.** A finalized reading can be unlocked to
  draw more cards, extending an existing spread rather than starting over.
- **Font Awesome Pro v7 (self-hosted kit)** with duotone iconography across the
  app, plus a font-management/testing surface for previewing heading fonts.
- A registry-driven icon system (`frontend/src/fontawesome.ts`) so icons are
  tree-shaken and type-checked at build time.
- Expanded automated tests, including `OriginGuard` middleware coverage.

### Changed

- Migrated remaining iconography off the legacy set onto the Pro kit's
  duotone/solid conventions.

## [3.0.0] — 2026-06-04

**Complete re-architecture.** The legacy server-rendered PHP/jQuery app was
rebuilt as a Vue 3 single-page application backed by a modern Slim 4 API, and
grew from an anonymous reading generator into a full account-based platform.

### Added

#### Accounts & authentication

- **User accounts** with email + password registration, **email activation**
  (single-use tokens, 24-hour expiry), login, logout, and a `/me` session check.
- **Password reset** flow with short-lived (1-hour) single-use tokens and an
  enumeration-safe "if an account exists…" response.
- **Self-service account management:** change display name, change password,
  and delete account (readings cascade-deleted via foreign keys).
- **Sign in with Google** (server-side OAuth2). Auto-links by verified email,
  auto-registers new users, and refuses to bind unverified Google emails.
- **WebAuthn passkeys** (`lbuchs/webauthn`): register, rename, and delete
  passkeys; passwordless login; and a per-account toggle to disable password
  login entirely.
- **Argon2id password hashing** (with transparent rehashing) and a shared
  password-strength policy.

#### Tarot domain

- **Deck systems** — card definitions (the 78-card structure, naming,
  meanings) are decoupled from individual decks, so many decks can share one
  system.
- **Readings** in three modes: free draw, spread-based, and custom layouts,
  with card placement, reversals, and finalization.
- **Spreads** (admin-curated and user-submitted), **favorites** (readings and
  decks), a public **contact form**, and a public **changelog**.
- **Deck/spread/deck-system community submissions** with an admin approval
  queue.

#### Admin panel

- A dedicated admin SPA section gated behind an `is_admin` account: dashboard
  with **usage insights** (readings per day, top decks/spreads, reading-type
  breakdown), plus management for decks, deck systems, special cards, spreads,
  pending submissions, readings, changelog, users, and contact messages.
- Deck **thumbnail generation** service.

#### Frontend platform

- **Vue 3 + TypeScript SPA** on Vite, with Vue Router, a composable-based data
  layer, a toast system, and a Markdown editor (TipTap + DOMPurify).
- **Bulma 1.0** theme with a custom dark tarot aesthetic.
- **Progressive Web App**: manifest, service worker, and app icons, plus an
  `og.php` server shim that injects per-reading Open Graph / Twitter meta for
  social sharing and SEO.
- **IndexNow** integration to push changed URLs to search engines.

### Changed

- Replaced the single `index.php` entrypoint and jQuery front-end with a Slim 4
  + PHP-DI backend (autowired container, compiled to disk in production) and a
  built SPA bundle.
- Moved PHP sessions to a **database-backed handler** with self-managed garbage
  collection, session-fixation protection (ID regeneration on privilege
  change), and a 30-day "remember me" persistence option.

### Removed

- Retired the legacy PHP page rendering and jQuery/AJAX entrypoints.

### Security

- **`OriginGuard` CSRF defense:** state-changing requests are rejected unless
  the `Origin`/`Referer` host matches the site (defense-in-depth atop
  `SameSite=Lax` cookies).
- Hardened session cookies (`HttpOnly`, `SameSite=Lax`, `Secure` in
  production), `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`,
  opt-in credentialed CORS (never `*`), and **per-IP rate limiting** on
  registration, login, and password-reset requests.

### Developer experience

- Introduced the modern toolchain: **Vite, TypeScript, ESLint**, **Vitest**
  (frontend) and **PHPUnit + PHPStan** (backend), a local dev router mirroring
  production `.htaccess`, and a SQLite dev database (no DB server required).

## [2.0.0] — 2026-04-17

First step away from the legacy stack.

### Added

- **Vue-driven reading UI**, replacing the jQuery interactions.
- Card **reversal viewing and flipping** in the card viewer, hover effects on
  cards, and a loading screen for slower draws.

### Changed

- Refreshed the Bulma-based styling.
- Capped the maximum reversal probability at 50%.

### Removed

- Dropped the jQuery and lightbox dependencies.

## [1.0.0] — 2025-04-08

Initial release.

### Added

- Server-rendered PHP tarot reading generator with a jQuery front-end and a
  lightbox-based card viewer.

[Unreleased]: https://github.com/jwill89/tarot-site/compare/v3.3.0...HEAD
[3.3.0]: https://github.com/jwill89/tarot-site/compare/v3.2.0...v3.3.0
[3.2.0]: https://github.com/jwill89/tarot-site/compare/v3.1.0...v3.2.0
[3.1.0]: https://github.com/jwill89/tarot-site/compare/v3.0.0...v3.1.0
[3.0.0]: https://github.com/jwill89/tarot-site/compare/v2.0.0...v3.0.0
[2.0.0]: https://github.com/jwill89/tarot-site/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/jwill89/tarot-site/releases/tag/v1.0.0
