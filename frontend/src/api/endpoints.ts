/**
 * Central registry of backend API paths for the (hybrid-REST) TarotGen.io API.
 *
 * ## Two conventions — read this before using an endpoint
 *
 * The backend serves everything under `/api`, and admin routes additionally
 * under `/api/admin`. Different call sites reach the API through different
 * mechanisms, so this module exposes paths in the two shapes those mechanisms
 * expect. Every value here is written WITHOUT a trailing slash (the backend
 * accepts `[/]`, so the canonical, slash-free form is used consistently).
 *
 * 1. `endpoints.*` — the path AFTER `/api`, starting at the resource and
 *    INCLUDING `/admin` where applicable (e.g. `/readings/generate`,
 *    `/auth/login`). Use these with:
 *      - `apiFetch` / `apiRequest`     → pass the value directly (the client
 *                                         prepends `/api` for you).
 *      - raw `fetch(...)`              → prepend `/api` yourself:
 *                                         `fetch('/api' + endpoints.xxx)`.
 *      - `window.location.href = ...`  → prepend `/api` (OAuth redirects).
 *
 * 2. `endpoints.admin.*` — the path relative to `/api/admin` (e.g. `/decks`,
 *    `/users/5`). Use these with `useAdminApi()` helpers (`get/post/put/patch/
 *    del`), which prepend `/api/admin` themselves. Passing an `endpoints.*`
 *    value that already contains `/admin` to those helpers would double it, so
 *    admin call sites must use `endpoints.admin.*`.
 *
 * Parameterized paths are functions; static paths are strings.
 */

// ---------------------------------------------------------------------------
// Public (path relative to /api)
// ---------------------------------------------------------------------------

export const endpoints = {
  /** `GET /api/config` — non-secret runtime config for the SPA. */
  config: '/config',

  decks: {
    /** `GET` list of usable decks / `POST` submit a new deck for review. */
    list: '/decks',
    byId: (id: string | number) => `/decks/${id}`,
    cards: (id: string | number) => `/decks/${id}/cards`,
  },

  deckSystems: {
    /** `GET` approved systems / `POST` submit a new system. */
    list: '/deck-systems',
    byId: (id: string | number) => `/deck-systems/${id}`,
  },

  spreads: {
    /** `GET` public spreads / `POST` submit a spread into moderation. */
    list: '/spreads',
    byId: (id: string | number) => `/spreads/${id}`,
  },

  readings: {
    /** `POST` — generate a random reading. */
    generate: '/readings/generate',
    /** `POST` — create a custom reading from chosen cards/positions. */
    create: '/readings',
    byId: (id: string | number) => `/readings/${id}`,
    /** `POST` — draw additional cards into a reading you own. */
    draw: (id: string | number) => `/readings/${id}/draw`,
    /** `POST` — permanently lock a reading against further draws. */
    finalize: (id: string | number) => `/readings/${id}/finalize`,
    /** `POST` — unlock a password-protected reading for the session. */
    unlock: (id: string | number) => `/readings/${id}/unlock`,
    /** `PUT` — save spread/placement positions onto a reading. */
    placement: (id: string | number) => `/readings/${id}/placement`,
  },

  /** `POST /api/contacts` — public contact-form submission. */
  contacts: '/contacts',

  changelog: {
    list: '/changelog',
    byId: (id: string | number) => `/changelog/${id}`,
  },

  // -----------------------------------------------------------------------
  // Auth (public; path relative to /api)
  // -----------------------------------------------------------------------

  auth: {
    register: '/auth/register',
    activate: '/auth/activate',
    forgotPassword: '/auth/forgot-password',
    resetPassword: '/auth/reset-password',
    login: '/auth/login',
    logout: '/auth/logout',
    /** `GET` — the current user, or 401. */
    me: '/auth/me',
  },

  /** Google OAuth (browser redirects + unlink). */
  authGoogle: {
    /** `GET` redirect to consent; carries `?intent=login|register|link`. */
    start: '/auth/google',
    callback: '/auth/google/callback',
    /** `POST` — unlink Google from the current account. */
    unlink: '/auth/google/unlink',
  },

  /** Passkeys / WebAuthn (renamed group: `/auth/passkeys`). */
  passkeys: {
    /** `GET` — list the current user's passkeys. */
    list: '/auth/passkeys',
    registerOptions: '/auth/passkeys/register/options',
    register: '/auth/passkeys/register',
    loginOptions: '/auth/passkeys/login/options',
    login: '/auth/passkeys/login',
    /** `PATCH` — toggle password login on/off. */
    passwordLogin: '/auth/passkeys/password-login',
    /** `PATCH` rename / `DELETE` a single passkey. */
    byId: (id: string | number) => `/auth/passkeys/${id}`,
  },

  // -----------------------------------------------------------------------
  // Account self-service (behind login; path relative to /api)
  // -----------------------------------------------------------------------

  account: {
    /** `DELETE /api/account` — delete the current account. */
    root: '/account',
    /** `PATCH /api/account` — update profile fields (e.g. display_name). */
    profile: '/account',
    /** `POST` — change password. */
    changePassword: '/account/change-password',

    readings: '/account/readings',
    readingById: (id: string | number) => `/account/readings/${id}`,

    spreads: '/account/spreads',
    spreadById: (id: string | number) => `/account/spreads/${id}`,
    spreadSubmit: (id: string | number) => `/account/spreads/${id}/submit`,

    favorites: '/account/favorites',
    /** `DELETE` — remove a favorite spread (ids now in the URL). */
    favoriteById: (spreadType: string, spreadId: string | number) =>
      `/account/favorites/${spreadType}/${spreadId}`,

    favoriteDecks: '/account/favorite-decks',
    /** `DELETE` — remove a favorite deck (id now in the URL). */
    favoriteDeckById: (deckId: string | number) => `/account/favorite-decks/${deckId}`,

    /** `GET` list linked plugin tokens (Connected Apps). */
    tokens: '/account/tokens',
    /** `DELETE /api/account/tokens/{id}` — revoke a linked plugin token. */
    tokenById: (id: string | number) => `/account/tokens/${id}`,
  },

  /** Dalamud plugin account linking (OAuth-style, PKCE) + guest relay connect. */
  plugin: {
    /** `POST` — browser consent approval → PKCE authorization code (session). */
    authorize: '/plugin/authorize',
    /** `POST` — mint a guest relay client token (no account; loopback handback). */
    guestAuthorize: '/plugin/guest-authorize',
    /** `POST` — plugin exchanges code + verifier for a Bearer token (public). */
    token: '/plugin/token',
  },

  // -----------------------------------------------------------------------
  // Admin — path relative to /api/admin (for `useAdminApi()` call sites).
  // These are also mirrored on the flat `endpoints.*` tree where a raw fetch
  // needs the full `/admin/...` path.
  // -----------------------------------------------------------------------

  admin: {
    /** Dashboard reads (unchanged by the REST normalization). */
    dashboard: {
      authCheck: '/auth-check',
      stats: '/stats',
      summary: '/summary',
    },
    decks: {
      list: '/decks',
      pending: '/decks/pending',
      create: '/decks',
      byId: (id: string | number) => `/decks/${id}`,
      approve: (id: string | number) => `/decks/${id}/approve`,
      thumbnails: (id: string | number) => `/decks/${id}/thumbnails`,
      allThumbnails: '/decks/thumbnails',
      specialCards: (deckId: string | number) => `/decks/${deckId}/special-cards`,
      specialCardById: (deckId: string | number, cardId: string | number) =>
        `/decks/${deckId}/special-cards/${cardId}`,
    },
    deckSystems: {
      list: '/deck-systems',
      pending: '/deck-systems/pending',
      byId: (id: string | number) => `/deck-systems/${id}`,
      approve: (id: string | number) => `/deck-systems/${id}/approve`,
    },
    spreads: {
      list: '/spreads',
      byId: (id: string | number) => `/spreads/${id}`,
    },
    pendingSpreads: {
      list: '/pending-spreads',
      approve: (id: string | number) => `/pending-spreads/${id}/approve`,
      byId: (id: string | number) => `/pending-spreads/${id}`,
    },
    readings: {
      list: '/readings',
      /** `DELETE /api/admin/readings/all?older_than_days=N` — bulk clean. */
      clean: (olderThanDays: string | number) => `/readings/all?older_than_days=${olderThanDays}`,
      byId: (id: string | number) => `/readings/${id}`,
    },
    changelog: {
      list: '/changelog',
      byId: (id: string | number) => `/changelog/${id}`,
    },
    users: {
      list: '/users',
      byId: (id: string | number) => `/users/${id}`,
      activate: (id: string | number) => `/users/${id}/activate`,
      resendActivation: (id: string | number) => `/users/${id}/resend-activation`,
    },
    contacts: {
      /** `GET` — pass `?show_read=1` to include read messages. */
      list: (showRead: boolean) => `/contacts?show_read=${showRead ? '1' : '0'}`,
      byId: (id: string | number) => `/contacts/${id}`,
    },
  },
} as const
