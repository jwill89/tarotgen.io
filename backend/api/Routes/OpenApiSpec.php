<?php

namespace Routes;

use OpenApi\Attributes as OA;

/**
 * Root OpenAPI document metadata: API info, the same-origin server, the session
 * security scheme, and the tag list that groups operations in the reference UI.
 *
 * This carries no runtime behaviour — it exists purely so `composer docs`
 * (swagger-php) can assemble `backend/openapi.json`, which the Scalar page at
 * `GET /api/docs` renders. Per-operation attributes live on the controllers; the
 * response/request schemas are the `#[OA\Schema]`-annotated Structure classes.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'TarotGen.io API',
    description: <<<'MD'
        The backend for **TarotGen.io** — a PHP 8.5 / Slim 4 hybrid-REST API.

        - Resources are plural nouns (`/decks`, `/readings`); items are `/{id}`.
        - `GET` reads, `POST` creates (→`201`) or runs a command, `PUT` replaces,
          `PATCH` applies a partial update / flag toggle, `DELETE` removes (→`204`).
        - State transitions that aren't a plain field-set are `POST …/{id}/{verb}`
          (e.g. `…/finalize`, `…/approve`, `…/draw`).
        - Errors are `{ "error": "…" }`; multi-message validation is `{ "errors": […] }`.
        - Auth is a session cookie (`PHPSESSID`) set by the `/auth/*` endpoints —
          there are no API keys or bearer tokens for first-party calls.
        MD
)]
#[OA\Server(url: '/api', description: 'Same-origin API base path')]
#[OA\SecurityScheme(
    securityScheme: 'sessionCookie',
    type: 'apiKey',
    in: 'cookie',
    name: 'PHPSESSID',
    description: 'Session cookie issued on sign-in. Sent automatically by the browser.'
)]
#[OA\SecurityScheme(
    securityScheme: 'pluginToken',
    type: 'http',
    scheme: 'bearer',
    description: 'Personal access token issued to a linked Dalamud plugin (Authorization: Bearer …).'
)]
#[OA\Tag(name: 'Config', description: 'Public runtime configuration.')]
#[OA\Tag(name: 'Decks', description: 'Tarot decks: public browsing and user submissions.')]
#[OA\Tag(name: 'Deck Systems', description: 'Card systems (e.g. Rider–Waite) a deck can follow.')]
#[OA\Tag(name: 'Spreads', description: 'Public spread layouts and user submissions.')]
#[OA\Tag(name: 'Readings', description: 'Generating, viewing, and modifying readings.')]
#[OA\Tag(name: 'Cards', description: "Card-level actions (e.g. reporting a problem with a card's image).")]
#[OA\Tag(name: 'Contact', description: 'Public contact-form submissions.')]
#[OA\Tag(name: 'Changelog', description: 'Public changelog entries.')]
#[OA\Tag(name: 'Authentication', description: 'Registration, sign-in/out, and session.')]
#[OA\Tag(name: 'Passkeys', description: 'WebAuthn passkey registration and login.')]
#[OA\Tag(name: 'Account', description: "The signed-in user's self-service area.")]
#[OA\Tag(name: 'Plugin', description: 'Dalamud plugin account linking (OAuth PKCE) and token management.')]
#[OA\Tag(name: 'Admin · Dashboard', description: 'Admin session check and usage stats.')]
#[OA\Tag(name: 'Admin · Decks', description: 'Deck moderation and special cards.')]
#[OA\Tag(name: 'Admin · Deck Systems', description: 'Deck-system moderation.')]
#[OA\Tag(name: 'Admin · Spreads', description: 'Public-spread CRUD and the pending queue.')]
#[OA\Tag(name: 'Admin · Readings', description: 'Reading listing and retention cleanup.')]
#[OA\Tag(name: 'Admin · Changelog', description: 'Changelog entry CRUD.')]
#[OA\Tag(name: 'Admin · Users', description: 'Account administration.')]
#[OA\Tag(name: 'Admin · Contacts', description: 'Submitted contact messages.')]
#[OA\Tag(name: 'Admin · Card Reports', description: 'Cards users have reported, with the reasons they gave.')]
// A spread's card slot. It has no Structure of its own (positions are stored as
// JSON on the spread), so it is declared here, in the shape
// Tarot\Data\NormalizesPositions stores: x/y are percentages of the canvas.
#[OA\Schema(
    schema: 'SpreadPosition',
    description: 'One card slot in a spread layout.',
    required: ['order', 'title', 'x', 'y', 'rotation'],
    properties: [
        new OA\Property(property: 'order', description: '1-based: the nth card drawn goes here.', type: 'integer'),
        new OA\Property(property: 'title', description: 'The slot\'s label, e.g. "Past".', type: 'string', maxLength: 100),
        new OA\Property(property: 'x', description: 'Card centre, % of the canvas width.', type: 'number', maximum: 100, minimum: 0),
        new OA\Property(property: 'y', description: 'Card centre, % of the canvas height.', type: 'number', maximum: 100, minimum: 0),
        new OA\Property(property: 'rotation', description: 'Degrees, 0–359.', type: 'integer', maximum: 359, minimum: 0),
    ]
)]
final class OpenApiSpec
{
}
