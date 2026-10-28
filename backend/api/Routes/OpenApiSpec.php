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
// What a reading holds (its reading_info JSON) and the two shapes the reading
// endpoints answer with. None has a Structure: reading_info is stored as JSON, and
// ReadingController builds the payloads (accessiblePayload, the locked stub).
#[OA\Schema(
    schema: 'DrawCard',
    description: 'One drawn card, in draw order.',
    required: ['card_id', 'reversed'],
    properties: [
        new OA\Property(property: 'card_id', description: "The card's number in its deck; a deck's extra cards come after its system's cards.", type: 'integer'),
        new OA\Property(property: 'reversed', description: 'Drawn upside down.', type: 'boolean'),
        new OA\Property(property: 'card_name', description: 'Absent when the deck has no name for the card (an extra card nobody has named yet).', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'SpreadSnapshot',
    description: "The layout as it was when the reading was made, so a shared reading still renders after its spread is edited or deleted.",
    required: ['spread_id', 'name', 'description', 'positions'],
    properties: [
        new OA\Property(property: 'spread_id', description: 'The public spread it came from; 0 for a personal spread, a custom reading or a free-draw placement.', type: 'integer'),
        new OA\Property(property: 'name', description: "The spread's name.", type: 'string'),
        new OA\Property(property: 'description', description: 'Markdown; may be empty.', type: 'string'),
        new OA\Property(property: 'positions', description: 'One per drawn card.', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpreadPosition')),
    ]
)]
#[OA\Schema(
    schema: 'ReadingInfo',
    description: 'What was drawn, from which deck, in which layout.',
    required: ['deck_id', 'draw'],
    properties: [
        new OA\Property(property: 'deck_id', description: 'The deck the cards were drawn from.', type: 'integer'),
        new OA\Property(property: 'origin', description: "How it was made; a custom reading can't draw more cards. Absent on readings made before this was recorded.", type: 'string', enum: ['generated', 'custom']),
        new OA\Property(property: 'spread', ref: '#/components/schemas/SpreadSnapshot', description: 'Absent for a free draw that was never placed.'),
        new OA\Property(property: 'draw', description: 'The cards, in draw order.', type: 'array', items: new OA\Items(ref: '#/components/schemas/DrawCard')),
    ]
)]
#[OA\Schema(
    schema: 'ReadingView',
    description: 'A reading as the requester may see it: what GET /readings/{reading_id} and the reading commands (generate, unlock, draw, finalize) return.',
    required: ['reading_id', 'reading_info', 'reading_time', 'reading_name', 'reading_notes', 'reader', 'is_owner', 'is_final', 'can_draw_more', 'locked'],
    properties: [
        new OA\Property(property: 'reading_id', description: 'The code in the reading link.', type: 'string'),
        new OA\Property(property: 'reading_info', ref: '#/components/schemas/ReadingInfo', description: 'What was drawn.'),
        new OA\Property(property: 'reading_time', description: 'When it was made, UTC ("Y-m-d H:i:s").', type: 'string'),
        new OA\Property(property: 'reading_name', description: "The owner's title for it, if any.", type: 'string', nullable: true),
        new OA\Property(property: 'reading_notes', description: "The owner's notes (Markdown), if any.", type: 'string', nullable: true),
        new OA\Property(property: 'reader', description: '"Guest", or the owner\'s display name unless they chose to hide it.', type: 'string'),
        new OA\Property(property: 'is_owner', description: 'Whether the requester owns it.', type: 'boolean'),
        new OA\Property(property: 'is_final', description: 'Locked for good against further draws.', type: 'boolean'),
        new OA\Property(property: 'can_draw_more', description: 'The requester may draw more cards into it: their own, not final, not custom.', type: 'boolean'),
        new OA\Property(property: 'locked', description: 'Always false here; see LockedReading.', type: 'boolean', enum: [false]),
    ]
)]
#[OA\Schema(
    schema: 'LockedReading',
    description: "Sent instead of the reading to anyone but its owner until they unlock it with its password (POST /readings/{reading_id}/unlock).",
    required: ['locked', 'reading_name'],
    properties: [
        new OA\Property(property: 'locked', description: 'Always true here.', type: 'boolean', enum: [true]),
        new OA\Property(property: 'reading_name', description: "The owner's title for it, if any — the only thing shown before unlocking.", type: 'string', nullable: true),
    ]
)]
final class OpenApiSpec
{
}
