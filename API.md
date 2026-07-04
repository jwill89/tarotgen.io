# TarotGen.io API

The backend is a **PHP 8.5 / Slim 4 hybrid-REST API**, served under the `/api` base
path and speaking JSON. This document is the **conventions** reference; the
authoritative, always-current **endpoint list is generated from the code** and served
as interactive docs:

| | |
|---|---|
| **Interactive reference (Scalar)** | [`GET /api/docs`](backend/api/index.php) |
| **Raw OpenAPI 3.1 spec** | [`GET /api/openapi.json`](backend/openapi.json) — committed at [`backend/openapi.json`](backend/openapi.json) |
| **Where it comes from** | swagger-php (`#[OA\...]`) attributes on the controllers in [`backend/api/Routes/Internal/`](backend/api/Routes/Internal/) and the `#[OA\Schema]` Structure classes in [`backend/includes/Tarot/Structure/`](backend/includes/Tarot/Structure/) |
| **Regenerate** | `cd backend && composer docs` (writes `openapi.json`); a PHPUnit test fails if the committed spec drifts |
| **Frontend types** | `cd frontend && npm run gen:types` → `frontend/src/types/api.generated.ts` |

> The route table itself lives in [`backend/api/index.php`](backend/api/index.php).
> See [`AGENTS.md`](AGENTS.md) §4 for the backend architecture (Controller → Service →
> Repository → Data → Structure) and the auth/CSRF model.

---

## Conventions (the "hybrid REST" rulebook)

The API is **hybrid REST**: resource-oriented CRUD with a small, deliberate set of
action endpoints for operations that aren't a plain field-set.

- **Base path** — every endpoint is under `/api`. Trailing slashes are accepted (`[/]`).
- **Methods**
  - `GET` reads.
  - `POST` **creates** (→ `201`) *or* runs a **command** (a non-CRUD process).
  - `PUT` fully **replaces** a resource representation.
  - `PATCH` applies a **partial update** or **flips a flag**.
  - `DELETE` removes (→ `204 No Content`, empty body).
- **Resources** — collections are **plural** nouns (`/decks`, `/spreads`, `/readings`);
  a single item is `/<resource>/{id}`; sub-collections nest
  (`/decks/{deck_id}/special-cards/{card_id}`).
- **Commands (non-CRUD)** — state transitions and processes that aren't a declarative
  field-set are `POST /<resource>/{id}/<verb>` — e.g. `…/finalize`, `…/unlock`,
  `…/draw`, `…/approve`, a user's `…/activate`. Flipping a boolean (a deck's `usable`,
  a user's `is_admin`, a contact's `is_read`) is a **`PATCH`** on the resource instead.
- **Bulk delete** — `DELETE /<resource>/all` (with a query filter where needed),
  returning `{ "deleted": N }` (e.g. `DELETE /admin/readings/all?older_than_days=30`).
- **Auth is the intentional exception** — credential/session endpoints stay
  action-oriented under `/auth/*` (`/auth/login`, `/auth/register`, `/auth/me`, …),
  alongside `/auth/google/*` and `/auth/passkeys/*`.

### Bodies & responses

| Topic | Detail |
|-------|--------|
| **Request body** | `POST`/`PUT`/`PATCH` send JSON (`Content-Type: application/json`); unknown fields are ignored. |
| **Success body** | The requested resource, a list, or `{ "success": true, … }`. Creates return `201`; deletes return `204` with no body. |
| **Error body** | Always `{ "error": "<message>" }`. Validation failures returning multiple messages use `{ "errors": ["…", "…"] }`. |
| **Caching** | Public, slow-changing reads send `Cache-Control: public, max-age=300`. Viewer-specific reads (a single reading) send `Cache-Control: private, no-store`. |
| **Rate limiting** | Several public endpoints are IP-rate-limited and answer `429` when exceeded (noted per endpoint in the spec). |

### Authentication & authorization

A signed-in **browser** user is identified by the `PHPSESSID` **session cookie**
(HttpOnly, SameSite=Lax, Secure in production) — the `sessionCookie` OpenAPI
scheme. The first-party SPA uses only this; there are no API keys.

The **Dalamud plugin** is the one non-browser client and authenticates with
**Bearer tokens** over HTTPS instead (a native `HttpClient` sends no `Origin`,
which `OriginGuard` already permits): a per-account **plugin token**
(`pluginToken` scheme) for the read-only account routes it may reach, and a
per-install **client token** (`clientToken` scheme) for the share relay. Both are
minted via a browser loopback + PKCE consent flow and are individually revocable.

| Audience | How a request authenticates | Guard |
|----------|-----------------------------|-------|
| Public | No session needed | — |
| Signed-in user (`/account/*`) | `PHPSESSID` for an **active** account | `UserAuth` → `401 Not authenticated` |
| Admin (`/admin/*`) | `PHPSESSID` for an active **`is_admin`** account | `AdminAuth` → `401 Unauthorized` |
| Linked plugin (account routes it may reach) | session **or** a plugin Bearer token | `AccountAuth` → `401 Not authenticated` |
| Plugin share relay (`/plugin/inbox`, `/plugin/share`, …) | a per-install client Bearer token | `ClientAuth` → `401` |

- **CSRF** — the `OriginGuard` middleware rejects state-changing requests
  (`POST`/`PUT`/`PATCH`/`DELETE`) from a foreign origin. Safe methods and the GET OAuth
  callback pass through.
- **CORS** — same-origin by default; cross-origin is enabled only when `APP_ORIGIN` is
  configured (never `*` with credentials).

Logging in is done via the auth endpoints (`POST /api/auth/login`, passkeys, or
Google) — there is no separate admin login.
