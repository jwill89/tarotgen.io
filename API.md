# TarotGen.io API Reference

The backend is a **PHP 8.5 / Slim 4 REST API**. Every route below is served under
the `/api` base path (e.g. `POST /api/user/login`) and speaks JSON. This document
is the authoritative list of endpoints; the route table itself lives in
[`backend/api/index.php`](backend/api/index.php), and each handler in
[`backend/api/Routes/Internal/`](backend/api/Routes/Internal/).

> See [`AGENTS.md`](AGENTS.md) §4 for the backend architecture (the
> Controller → Service → Repository → Data → Structure layering) and the auth /
> CSRF model summarized below.

---

## Conventions

| Topic | Detail |
|-------|--------|
| **Base path** | All endpoints are prefixed with `/api`. Trailing slashes are accepted (`[/]`). |
| **Request body** | `POST`/`PUT` send JSON (`Content-Type: application/json`). Bodies are parsed into an associative array; unknown fields are ignored. |
| **Success body** | The requested resource, a list, or `{ "success": true, … }`. |
| **Error body** | Always `{ "error": "<message>" }`. Validation failures that return multiple messages use `{ "errors": ["…", "…"] }`. |
| **Auth** | A signed-in user is identified by the `PHPSESSID` session cookie (HttpOnly, SameSite=Lax, Secure in production). There are no API keys or bearer tokens for first-party calls. |
| **Admin** | Admin routes require a logged-in account whose `is_admin` flag is set; the flag is re-checked from the database on every request. |
| **CSRF** | The `OriginGuard` middleware rejects state-changing requests (`POST`/`PUT`/`PATCH`/`DELETE`) from a foreign origin. Safe methods and the GET OAuth callback pass through. |
| **CORS** | Same-origin by default. Cross-origin access is enabled only when `APP_ORIGIN` is configured (never `*` with credentials). |
| **Rate limiting** | Several public endpoints are IP-rate-limited and answer `429` with an `{ "error" }` body when exceeded (limits noted per endpoint). |
| **Caching** | Public, slow-changing reads send `Cache-Control: public, max-age=300`. Viewer-specific reads (a single reading) send `Cache-Control: private, no-store`. |

### Authentication model

| Audience | How a request authenticates | Guard |
|----------|-----------------------------|-------|
| Public | No session needed | — |
| Signed-in user (`/account/*`) | `PHPSESSID` cookie for an **active** account | `UserAuth` |
| Admin (`/admin/*`) | `PHPSESSID` cookie for an active **`is_admin`** account | `AdminAuth` |

A failed guard returns `401` with `{ "error": "Not authenticated" }` (user) or
`{ "error": "Unauthorized" }` (admin). Logging in is done via the user endpoints
(`POST /api/user/login`, passkeys, or Google) — there is no separate admin login.

---

## Public configuration

### `GET /config`
Non-secret runtime config the SPA needs before rendering.

**Response** `200`
```json
{ "turnstile_sitekey": "0x4AAA…" | null }
```
`turnstile_sitekey` is `null` when Cloudflare Turnstile isn't configured; the
frontend then skips the login CAPTCHA.

---

## Decks

### `GET /deck`
List all **usable** decks (public selector). Cached 5 min.

### `GET /deck/{deck_id}`
A single deck (with its deck-system fields). `404 InvalidDeckID` if missing. Cached 5 min.

### `GET /deck/{deck_id}/cards`
Every card available in the deck as `[{ "card_id": int, "name": string }]`, including
extras — used by the custom-reading card picker. Cached 5 min.

### `POST /deck/submit`
Submit a new deck for review (**requires a logged-in user**; `401` otherwise). The
deck is created unapproved and not usable.

**Body**: `name`*, `artist`*, `purchase_url`, `deck_system_id`, `additional_cards`.
**Response** `201` the created deck, or `400` when name/artist are blank.

---

## Deck systems

### `GET /deck-system`
List all approved deck systems. Cached 5 min.

### `GET /deck-system/{id}`
A single deck system plus its `cards` array. `404` if not found. Cached 5 min.

### `POST /deck-system/submit`
Submit a deck system (**requires auth**). Admins' submissions are auto-approved;
others land pending. Requires `name`*, `short_name`*, `total_cards`, and a `cards`
array (each card needs at least a `name`); `400` on validation failure.

---

## Spreads

### `GET /spread`
List all public spreads. Cached 5 min.

### `GET /spread/{spread_id}`
A single public spread. `404 InvalidSpreadID` if missing.

### `POST /spread/submit`
Public submission into the moderation queue. **Rate-limited to 5/IP/hour** (`429`).
Requires `name`* and a non-empty `positions` array; never echoes the stored row
(`201 { "success": true }`).

---

## Readings

A reading's `reading_info` is stored as JSON and returned as a nested object.

### `POST /reading/new`
Generate a random reading. Body is the draw spec (deck, spread/card count, options).
Returns the created reading, or an `{ "error" }` with the relevant status on
invalid input (`ApiException`).

### `POST /reading/custom`
Create a custom reading from explicitly chosen cards/positions.

### `GET /reading/{reading_id}`
Fetch a reading. Password-protected readings return only
`{ "locked": true, "reading_name": … }` to non-owners who haven't unlocked it this
session. `Cache-Control: private, no-store`.

### `POST /reading/{reading_id}/unlock`
Unlock a password-protected reading for the session. **Body**: `password`.
`401 Incorrect password.` on mismatch.

### `PUT /reading/{reading_id}/placement`
Save spread/placement positions onto an existing reading (owner or the original
guest, before navigating away). The position count must match the drawn-card count.

### `POST /reading/{reading_id}/draw`
Draw additional cards into a non-final, non-custom reading **you own**
(`403`/`409`/`400` for ownership, finalized, or custom readings respectively).

### `PUT /reading/{reading_id}/finalize`
Permanently lock a reading against further draws (owner-only, one-way).

---

## Contact

### `POST /contact`
Public contact-form submission. **Rate-limited to 5/IP/hour** (`429`). Requires
`name`*, `email`* (valid), `message`*. `201 { "success": true }`.

---

## Changelog

### `GET /changelog`
List all changelog entries (newest first). Cached 5 min.

### `GET /changelog/{entry_id}`
A single entry, or `404 InvalidEntryID`.

---

## User accounts (public)

All bodies are JSON. Password fields are treated as
[`#[\SensitiveParameter]`](https://www.php.net/manual/en/class.sensitiveparameter.php)
server-side (redacted from stack traces).

### `POST /user/register`
Create an account (inactive until email activation). **Rate-limited to 5/IP/hour.**
**Body**: `email`*, `display_name`*, `password`* (≥12 chars, policy-checked).
**Response** `201 { "success": true, "message": … }`; `422 { "errors": [...] }` on
validation/uniqueness failure. Outside production with no SMTP configured, the
response also includes `activation_link` for testing.

### `POST /user/activate`
Activate from the emailed token. **Body**: `token`. `400` if invalid/expired.

### `POST /user/forgot-password`
Begin a password reset. **Rate-limited to 5/IP/hour.** **Body**: `email`. Always
answers `200` with an identical message (no account enumeration); dev responses may
include `reset_link`.

### `POST /user/reset-password`
Complete a reset. **Body**: `token`, `password`. `422` if the new password is too
weak (the token is not consumed, so the link can be retried); `400` if the token is
invalid/expired. On success the account is also marked active.

### `POST /user/login`
Password sign-in. **Rate-limited to 10 failed attempts/IP/15 min.** **Body**:
`email`*, `password`*, optional `remember_me`, and `turnstile_token` (required when
Turnstile is configured). Returns `{ "success": true, "user": … }` and sets the
session cookie. Errors: `400` captcha, `401` invalid credentials, `403` inactive or
password-login-disabled.

### `POST /user/logout`
Clear the session. `200 { "success": true }`.

### `GET /user/me`
The current user (`{ "user": … }`) or `401` when not signed in.

---

## Account self-service (`/account/*`, requires `UserAuth`)

| Method & path | Purpose |
|---------------|---------|
| `GET /account/readings` | The user's readings, newest first. |
| `PUT /account/readings/{reading_id}` | Update a reading's `reading_name`, `reading_notes`, `hide_user`, or view `password` (send `remove_password` to clear). |
| `DELETE /account/readings/{reading_id}` | Delete one of the user's readings. |
| `GET /account/spreads` | The user's personal spreads. |
| `POST /account/spreads` | Create a personal spread (`name`* + `positions`*). |
| `PUT /account/spreads/{user_spread_id}` | Update a personal spread. |
| `DELETE /account/spreads/{user_spread_id}` | Delete a personal spread (and any favorites pointing to it). |
| `POST /account/spreads/{user_spread_id}/submit` | Submit a personal spread to the public pending queue. |
| `GET /account/favorites` | Favorited spreads. |
| `POST /account/favorites` | Add a favorite (`spread_type` `public`\|`personal`, `spread_id`). |
| `DELETE /account/favorites` | Remove a favorite (same body). |
| `GET /account/favorite-decks` | Favorited decks. |
| `POST /account/favorite-decks` | Add a favorite deck (`deck_id`). |
| `DELETE /account/favorite-decks` | Remove a favorite deck (`deck_id`). |
| `PUT /account/display-name` | Change display name (`display_name`); `422` on policy/uniqueness failure. |
| `PUT /account/password` | Change password (`current_password`, `new_password`); `422` on wrong current or weak new. |
| `DELETE /account` | Delete the account after re-entering `password`; cascades readings. `403` on wrong password. |

---

## Google OAuth

Configured via `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI`;
`503` when not configured. Intent (`login` | `register` | `link`) is carried in the
session; an unverified Google email is rejected for login/register to prevent
account takeover.

### `GET /auth/google`
Redirect (`302`) to Google's consent screen. Optional `?intent=` query param. The
`link` intent requires an existing session (`401` otherwise).

### `GET /auth/google/callback`
OAuth callback. Redirects back into the SPA on success/failure (a safe `GET`, so it
passes `OriginGuard`).

### `POST /auth/google/unlink`
Unlink Google from the current account (**requires session**).

---

## Passkeys / WebAuthn

WebAuthn via `lbuchs/webauthn`. Registration is session-gated; login is public.

| Method & path | Purpose |
|---------------|---------|
| `POST /auth/passkey/register/options` | Credential-creation options (requires session). |
| `POST /auth/passkey/register` | Complete registration (`clientDataJSON`, `attestationObject`, `name`). |
| `POST /auth/passkey/login/options` | Assertion options; optional `email` scopes to a user's credentials. |
| `POST /auth/passkey/login` | Verify an assertion (`id`, `clientDataJSON`, `authenticatorData`, `signature`) and sign in. |
| `GET /auth/passkey` | List the current user's passkeys (requires session). |
| `PUT /auth/passkey/password-login` | Toggle password login on/off (`disable`); requires ≥1 passkey before disabling. |
| `PUT /auth/passkey/{id}` | Rename a passkey (`name`). |
| `DELETE /auth/passkey/{id}` | Delete a passkey (blocked if it's the last one while password login is disabled). |

---

## Admin (`/admin/*`, requires `AdminAuth`)

### Dashboard
| Method & path | Purpose |
|---------------|---------|
| `GET /admin/auth-check` | Confirm admin session (`{ "authenticated": true }`). |
| `GET /admin/stats` | Usage analytics (totals, by-type, top decks/spreads, daily). |
| `GET /admin/summary` | One-shot `{ counts, stats }` for the dashboard. |

### Decks
| Method & path | Purpose |
|---------------|---------|
| `GET /admin/decks` | Approved decks. |
| `GET /admin/decks/pending` | Decks awaiting review. |
| `POST /admin/decks` | Create a deck (auto-approved, not usable). |
| `POST /admin/decks/thumbnails` | Regenerate thumbnails for **all** decks. |
| `POST /admin/decks/{deck_id}/approve` | Approve a deck. |
| `POST /admin/decks/{deck_id}/usable` | Toggle `usable` (creates image folders when enabling). |
| `POST /admin/decks/{deck_id}/thumbnails` | Regenerate one deck's thumbnails. |
| `PUT /admin/decks/{deck_id}` | Update a deck. |
| `DELETE /admin/decks/{deck_id}` | Delete a deck. |

### Deck systems
| Method & path | Purpose |
|---------------|---------|
| `GET /admin/deck-systems` · `…/pending` · `…/{id}` | List approved · pending · single (with cards). |
| `POST /admin/deck-systems/{id}/approve` | Approve a system. |
| `PUT /admin/deck-systems/{id}` | Update a system and (optionally) replace its `cards`. |
| `DELETE /admin/deck-systems/{id}` | Delete a system. |

### Special cards
| Method & path | Purpose |
|---------------|---------|
| `GET /admin/special-cards` | List (optional `?deck_id=`). |
| `POST /admin/special-cards` | Create. |
| `PUT /admin/special-cards/{deck_id}/{card_id}` | Update. |
| `DELETE /admin/special-cards/{deck_id}/{card_id}` | Delete. |

### Spreads & pending spreads
| Method & path | Purpose |
|---------------|---------|
| `GET /admin/spreads` · `POST` · `PUT /{spread_id}` · `DELETE /{spread_id}` | CRUD public spreads. |
| `GET /admin/pending-spreads` | User submissions awaiting approval. |
| `POST /admin/pending-spreads/{pending_id}/approve` | Approve into the public list. |
| `DELETE /admin/pending-spreads/{pending_id}` | Reject. |

### Readings
| Method & path | Purpose |
|---------------|---------|
| `GET /admin/readings` | Paginated listing (`?limit=`, `?offset=`) with resolved deck names. |
| `POST /admin/readings/clean` | Delete guest readings older than `days` (allow-list: 7/14/30/60/90/180/365). |
| `DELETE /admin/readings/{reading_id}` | Delete a reading. |

### Changelog
| Method & path | Purpose |
|---------------|---------|
| `GET /admin/changelog` · `POST` · `PUT /{entry_id}` · `DELETE /{entry_id}` | CRUD changelog entries. |

### Users
| Method & path | Purpose |
|---------------|---------|
| `GET /admin/users` | All accounts (newest first). |
| `POST /admin/users/{user_id}/activate` | Manually activate. |
| `POST /admin/users/{user_id}/admin` | Set the `is_admin` flag (`is_admin` body). |
| `POST /admin/users/{user_id}/resend-activation` | Re-send the activation email. |
| `DELETE /admin/users/{user_id}` | Delete an account. |

### Contacts
| Method & path | Purpose |
|---------------|---------|
| `GET /admin/contacts` | Submitted messages (`?show_read=1` to include read). |
| `POST /admin/contacts/{contact_id}/read` | Mark read/unread (`is_read` body). |

---

\* = required field. Fields not marked are optional and fall back to a sensible default.
