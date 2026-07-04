<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Config\Env;
use Tarot\Repository\CardReportRepository;
use Tarot\Repository\ChangelogRepository;
use Tarot\Repository\ContactRepository;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\DeckSystemRepository;
use Tarot\Repository\FavoriteSpreadRepository;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\SpecialCardRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\AuthService;
use Tarot\Service\StatsService;
use Tarot\Service\ThumbnailService;
use Tarot\Structure\DeckSystem;

class AdminController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $decks,
        private readonly DeckSystemRepository $deckSystems,
        private readonly SpecialCardRepository $specialCards,
        private readonly SpreadRepository $spreads,
        private readonly PendingSpreadRepository $pendingSpreads,
        private readonly ChangelogRepository $changelog,
        private readonly ContactRepository $contacts,
        private readonly CardReportRepository $cardReports,
        private readonly ReadingRepository $readings,
        private readonly StatsService $stats,
        private readonly ThumbnailService $thumbnails,
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly FavoriteSpreadRepository $favorites,
    ) {
    }

    // ── Dashboard stats ─────────────────────────────────────────

    #[OA\Get(
        path: '/admin/stats',
        summary: 'Usage statistics',
        tags: ['Admin · Dashboard'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Usage statistics',
                content: new OA\JsonContent(type: 'object')
            ),
        ]
    )]
    public function getStats(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->stats->overview());
    }

    /**
     * One-shot dashboard payload: entity counts (cheap COUNT(*) queries) plus the
     * usage stats overview — so the dashboard loads with a single request.
     */
    #[OA\Get(
        path: '/admin/summary',
        summary: 'Dashboard counts + stats',
        tags: ['Admin · Dashboard'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dashboard counts + stats',
                content: new OA\JsonContent(type: 'object')
            ),
        ]
    )]
    public function getSummary(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson([
            'counts' => $this->stats->counts(),
            'stats'  => $this->stats->overview(),
        ]);
    }

    // ── Authentication ──────────────────────────────────────────
    // Admin access is gated by AdminAuth (an active is_admin user account);
    // logging in/out is handled by the regular auth endpoints (/api/auth/*).

    #[OA\Get(
        path: '/admin/auth-check',
        summary: 'Verify the admin session',
        tags: ['Admin · Dashboard'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Admin session is valid'),
        ]
    )]
    public function checkAuth(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson(['authenticated' => true]);
    }


    // ── Decks ───────────────────────────────────────────────────

    #[OA\Get(
        path: '/admin/decks',
        summary: 'List all approved decks',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of decks',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Deck'))
            ),
        ]
    )]
    public function getDecks(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->decks->getApproved());
    }

    #[OA\Get(
        path: '/admin/decks/pending',
        summary: 'List decks awaiting approval',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of pending decks',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Deck'))
            ),
        ]
    )]
    public function getPendingDecks(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->decks->getPending());
    }

    #[OA\Post(
        path: '/admin/decks',
        summary: 'Create a deck',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'artist', type: 'string'),
                    new OA\Property(property: 'purchase_url', type: 'string'),
                    new OA\Property(property: 'deck_system_id', type: 'integer'),
                    new OA\Property(property: 'additional_cards', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created deck',
                content: new OA\JsonContent(ref: '#/components/schemas/Deck')
            ),
            new OA\Response(response: 500, description: 'Failed to create deck'),
        ]
    )]
    public function createDeck(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        // Admin-created decks are auto-approved but NOT auto-usable.
        $params['approved'] = true;
        $params['usable']   = false;
        $deck = $this->decks->create($params);

        if ($deck === null) {
            return $response->withJson(['error' => 'Failed to create deck'], 500);
        }

        return $response->withJson($deck, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/admin/decks/{deck_id}/approve',
        summary: 'Approve a pending deck',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Approved deck',
                content: new OA\JsonContent(ref: '#/components/schemas/Deck')
            ),
            new OA\Response(response: 404, description: 'Deck not found'),
        ]
    )]
    public function approveDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);

        $deck = $this->decks->update($deck_id, ['approved' => true]);

        if ($deck === null) {
            return $response->withJson(['error' => 'Deck not found'], 404);
        }

        return $response->withJson($deck);
    }

    // ── Thumbnails ──────────────────────────────────────────────

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/admin/decks/{deck_id}/thumbnails',
        summary: 'Generate thumbnails for a deck',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Thumbnail generation result',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 400, description: 'Invalid deck ID'),
        ]
    )]
    public function generateDeckThumbnails(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        @set_time_limit(0);

        $deck_id = (int)($args['deck_id'] ?? 0);
        if ($deck_id < 1) {
            return $response->withJson(['error' => 'Invalid deck ID'], 400);
        }

        return $response->withJson($this->thumbnails->generateForDeck($deck_id));
    }

    #[OA\Post(
        path: '/admin/decks/thumbnails',
        summary: 'Generate thumbnails for all decks',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Thumbnail generation result',
                content: new OA\JsonContent(type: 'object')
            ),
        ]
    )]
    public function generateAllThumbnails(Request $request, Response $response): Response|ResponseInterface
    {
        @set_time_limit(0);

        $ids = [];
        foreach ($this->decks->getAll() as $deck) {
            $ids[] = $deck->deck_id;
        }

        return $response->withJson($this->thumbnails->generateAll($ids));
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Patch(
        path: '/admin/decks/{deck_id}',
        summary: 'Partially update a deck',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Partial set of deck fields to update',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'artist', type: 'string'),
                    new OA\Property(property: 'usable', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated deck',
                content: new OA\JsonContent(ref: '#/components/schemas/Deck')
            ),
            new OA\Response(response: 404, description: 'Deck not found or no valid fields'),
        ]
    )]
    public function updateDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);
        $params = $this->parsedBody($request);

        $deck = $this->decks->update($deck_id, $params);

        if ($deck === null) {
            return $response->withJson(['error' => 'Deck not found or no valid fields'], 404);
        }

        // Enabling a deck creates its on-disk image folders. This was previously
        // the dedicated POST …/usable action; it is now a PATCH field carrying the
        // same side effect.
        if (array_key_exists('usable', $params) && (bool)$params['usable']) {
            $this->thumbnails->ensureDeckFolders($deck->deck_id);
        }

        return $response->withJson($deck);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/admin/decks/{deck_id}',
        summary: 'Delete a deck',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 500, description: 'Failed to delete deck'),
        ]
    )]
    public function deleteDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);

        $result = $this->decks->delete($deck_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete deck'], 500);
        }

        return $response->withStatus(204);
    }

    // ── Special Cards (nested under their deck) ─────────────────

    /**
     * @param array<string,string> $args
     */
    #[OA\Get(
        path: '/admin/decks/{deck_id}/special-cards',
        summary: 'List a deck\'s special cards',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of special cards',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/SpecialCard'))
            ),
        ]
    )]
    public function getSpecialCards(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);

        return $response->withJson($this->specialCards->getAll($deck_id));
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/admin/decks/{deck_id}/special-cards',
        summary: 'Add a special card to a deck',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['card_id'],
                properties: [
                    new OA\Property(property: 'card_id', type: 'integer'),
                    new OA\Property(property: 'name', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created special card',
                content: new OA\JsonContent(ref: '#/components/schemas/SpecialCard')
            ),
            new OA\Response(response: 500, description: 'Failed to create special card'),
        ]
    )]
    public function createSpecialCard(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        // The deck is identified by the path, not the body.
        $params['deck_id'] = (int)($args['deck_id'] ?? 0);
        $card = $this->specialCards->create($params);

        if ($card === null) {
            return $response->withJson(['error' => 'Failed to create special card'], 500);
        }

        return $response->withJson($card, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Put(
        path: '/admin/decks/{deck_id}/special-cards/{card_id}',
        summary: 'Update a special card',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'card_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated special card',
                content: new OA\JsonContent(ref: '#/components/schemas/SpecialCard')
            ),
            new OA\Response(response: 404, description: 'Special card not found'),
        ]
    )]
    public function updateSpecialCard(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);
        $card_id = (int)($args['card_id'] ?? 0);
        $params = $this->parsedBody($request);

        $card = $this->specialCards->update($deck_id, $card_id, $params);

        if ($card === null) {
            return $response->withJson(['error' => 'Special card not found'], 404);
        }

        return $response->withJson($card);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/admin/decks/{deck_id}/special-cards/{card_id}',
        summary: 'Delete a special card',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'card_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 500, description: 'Failed to delete special card'),
        ]
    )]
    public function deleteSpecialCard(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);
        $card_id = (int)($args['card_id'] ?? 0);

        $result = $this->specialCards->delete($deck_id, $card_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete special card'], 500);
        }

        return $response->withStatus(204);
    }

    // ── Spreads ─────────────────────────────────────────────────

    #[OA\Get(
        path: '/admin/spreads',
        summary: 'List all public spreads',
        tags: ['Admin · Spreads'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of spreads',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Spread'))
            ),
        ]
    )]
    public function getSpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->spreads->get());
    }

    #[OA\Post(
        path: '/admin/spreads',
        summary: 'Create a public spread',
        tags: ['Admin · Spreads'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'positions', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created spread',
                content: new OA\JsonContent(ref: '#/components/schemas/Spread')
            ),
            new OA\Response(response: 500, description: 'Failed to create spread'),
        ]
    )]
    public function createSpread(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        $spread = $this->spreads->create($params);

        if ($spread === null) {
            return $response->withJson(['error' => 'Failed to create spread'], 500);
        }

        return $response->withJson($spread, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Put(
        path: '/admin/spreads/{spread_id}',
        summary: 'Update a public spread',
        tags: ['Admin · Spreads'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'positions', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated spread',
                content: new OA\JsonContent(ref: '#/components/schemas/Spread')
            ),
            new OA\Response(response: 404, description: 'Spread not found or no valid fields'),
        ]
    )]
    public function updateSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spread_id = (int)($args['spread_id'] ?? 0);
        $params = $this->parsedBody($request);

        $spread = $this->spreads->update($spread_id, $params);

        if ($spread === null) {
            return $response->withJson(['error' => 'Spread not found or no valid fields'], 404);
        }

        return $response->withJson($spread);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/admin/spreads/{spread_id}',
        summary: 'Delete a public spread',
        tags: ['Admin · Spreads'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
        ]
    )]
    public function deleteSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spread_id = (int)($args['spread_id'] ?? 0);

        $result = $this->spreads->delete($spread_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete spread'], 500);
        }

        // Remove any user favorites pointing to this deleted public spread.
        $this->favorites->removeBySpread('public', $spread_id);

        return $response->withStatus(204);
    }

    // ── Pending Spreads (user submissions) ──────────────────────

    #[OA\Get(
        path: '/admin/pending-spreads',
        summary: 'List pending spread submissions',
        tags: ['Admin · Spreads'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pending spread submissions',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            ),
        ]
    )]
    public function getPendingSpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->pendingSpreads->get());
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/admin/pending-spreads/{pending_id}/approve',
        summary: 'Approve a pending spread submission',
        tags: ['Admin · Spreads'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'pending_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Approved spread',
                content: new OA\JsonContent(ref: '#/components/schemas/Spread')
            ),
            new OA\Response(response: 500, description: 'Failed to approve spread'),
        ]
    )]
    public function approvePendingSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $pending_id = (int)($args['pending_id'] ?? 0);

        $spread = $this->pendingSpreads->approve($pending_id);

        if ($spread === null) {
            return $response->withJson(['error' => 'Failed to approve spread'], 500);
        }

        return $response->withJson($spread, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/admin/pending-spreads/{pending_id}',
        summary: 'Reject a pending spread submission',
        tags: ['Admin · Spreads'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'pending_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Rejected'),
            new OA\Response(response: 500, description: 'Failed to reject spread'),
        ]
    )]
    public function rejectPendingSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $pending_id = (int)($args['pending_id'] ?? 0);

        $result = $this->pendingSpreads->reject($pending_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to reject spread'], 500);
        }

        return $response->withStatus(204);
    }

    // ── Changelog ───────────────────────────────────────────────

    #[OA\Get(
        path: '/admin/changelog',
        summary: 'List all changelog entries',
        tags: ['Admin · Changelog'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of changelog entries',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/ChangelogEntry')
                )
            ),
        ]
    )]
    public function getChangelog(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->changelog->get());
    }

    #[OA\Post(
        path: '/admin/changelog',
        summary: 'Create a changelog entry',
        tags: ['Admin · Changelog'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'body', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created changelog entry',
                content: new OA\JsonContent(ref: '#/components/schemas/ChangelogEntry')
            ),
            new OA\Response(response: 500, description: 'Failed to create changelog entry'),
        ]
    )]
    public function createChangelogEntry(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        $entry = $this->changelog->create($params);

        if ($entry === null) {
            return $response->withJson(['error' => 'Failed to create changelog entry'], 500);
        }

        return $response->withJson($entry, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Put(
        path: '/admin/changelog/{entry_id}',
        summary: 'Update a changelog entry',
        tags: ['Admin · Changelog'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'entry_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'body', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated changelog entry',
                content: new OA\JsonContent(ref: '#/components/schemas/ChangelogEntry')
            ),
            new OA\Response(response: 404, description: 'Changelog entry not found or no valid fields'),
        ]
    )]
    public function updateChangelogEntry(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $entry_id = (int)($args['entry_id'] ?? 0);
        $params = $this->parsedBody($request);

        $entry = $this->changelog->update($entry_id, $params);

        if ($entry === null) {
            return $response->withJson(['error' => 'Changelog entry not found or no valid fields'], 404);
        }

        return $response->withJson($entry);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/admin/changelog/{entry_id}',
        summary: 'Delete a changelog entry',
        tags: ['Admin · Changelog'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'entry_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
        ]
    )]
    public function deleteChangelogEntry(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $entry_id = (int)($args['entry_id'] ?? 0);

        $result = $this->changelog->delete($entry_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete changelog entry'], 500);
        }

        return $response->withStatus(204);
    }

    // ── Users ───────────────────────────────────────────────────

    #[OA\Get(
        path: '/admin/users',
        summary: 'List all user accounts',
        tags: ['Admin · Users'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of users',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/User'))
            ),
        ]
    )]
    public function getUsers(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->users->getAll());
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/admin/users/{user_id}/activate',
        summary: 'Activate a user account',
        tags: ['Admin · Users'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Activated',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'success', type: 'boolean')]
                )
            ),
            new OA\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function activateUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        if ($user_id < 1 || $this->users->findById($user_id) === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $this->users->activate($user_id);

        return $response->withJson(['success' => true]);
    }

    /**
     * Partial update of an account. Currently the only editable field is the
     * `is_admin` flag (previously the dedicated POST …/admin action).
     *
     * @param array<string,string> $args
     */
    #[OA\Patch(
        path: '/admin/users/{user_id}',
        summary: 'Partially update a user account',
        tags: ['Admin · Users'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'is_admin', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated user',
                content: new OA\JsonContent(ref: '#/components/schemas/User')
            ),
            new OA\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function updateUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        if ($user_id < 1 || $this->users->findById($user_id) === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $params = $this->parsedBody($request);

        if (array_key_exists('is_admin', $params)) {
            $this->users->setAdmin($user_id, (bool)$params['is_admin']);
        }

        // Return the refreshed user so the client can update the row in place.
        return $response->withJson($this->users->findById($user_id));
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/admin/users/{user_id}/resend-activation',
        summary: 'Resend a user activation email',
        tags: ['Admin · Users'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Activation resent',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(response: 400, description: 'Could not resend activation'),
        ]
    )]
    public function resendActivation(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        $result = $this->auth->resendActivation($user_id);

        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not resend activation.'], 400);
        }

        $payload = ['success' => true, 'emailed' => $result['emailed'] ?? false];

        // Outside production, surface the link when email isn't configured.
        if (!Env::isProduction() && empty($result['emailed'])) {
            $payload['activation_link'] = $result['activation_link'] ?? null;
        }

        return $response->withJson($payload);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/admin/users/{user_id}',
        summary: 'Delete a user account',
        tags: ['Admin · Users'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'user_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function deleteUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        if ($user_id < 1 || $this->users->findById($user_id) === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $this->users->delete($user_id);

        return $response->withStatus(204);
    }

    // ── Contacts ─────────────────────────────────────────────────

    #[OA\Get(
        path: '/admin/contacts',
        summary: 'List submitted contact messages',
        tags: ['Admin · Contacts'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(
                name: 'show_read',
                in: 'query',
                required: false,
                description: 'Set to "1" to include already-read messages',
                schema: new OA\Schema(type: 'string', enum: ['0', '1'])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of contacts',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Contact'))
            ),
        ]
    )]
    public function getContacts(Request $request, Response $response): Response|ResponseInterface
    {
        $showRead = ($request->getQueryParams()['show_read'] ?? '0') === '1';
        $unreadOnly = $showRead ? null : true;

        return $response->withJson($this->contacts->get($unreadOnly));
    }

    /**
     * Partial update of a contact message. Currently the only editable field is
     * the `is_read` flag (previously the dedicated POST …/read action).
     *
     * @param array<string,string> $args
     */
    #[OA\Patch(
        path: '/admin/contacts/{contact_id}',
        summary: 'Partially update a contact message',
        tags: ['Admin · Contacts'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'contact_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'is_read', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'success', type: 'boolean')]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid contact ID'),
        ]
    )]
    public function updateContact(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $contact_id = (int)($args['contact_id'] ?? 0);

        if ($contact_id < 1) {
            return $response->withJson(['error' => 'Invalid contact ID'], 400);
        }

        $params = $this->parsedBody($request);

        if (array_key_exists('is_read', $params)) {
            $this->contacts->markRead($contact_id, (bool)$params['is_read']);
        }

        return $response->withJson(['success' => true]);
    }

    // ── Card reports (scan issues submitted from the card lightbox) ──

    #[OA\Get(
        path: '/admin/card-reports',
        summary: 'List reported card scans (open first; pass ?show_resolved=1 for all)',
        tags: ['Admin · Card Reports'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(
                name: 'show_resolved',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of card reports',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CardReport'))
            ),
        ]
    )]
    public function getCardReports(Request $request, Response $response): Response|ResponseInterface
    {
        $includeResolved = ($request->getQueryParams()['show_resolved'] ?? '0') === '1';

        return $response->withJson($this->cardReports->get($includeResolved));
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Patch(
        path: '/admin/card-reports/{report_id}',
        summary: 'Mark a card report resolved / reopened',
        tags: ['Admin · Card Reports'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'report_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [new OA\Property(property: 'resolved', type: 'boolean')])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean')])
            ),
            new OA\Response(response: 400, description: 'Invalid report ID'),
        ]
    )]
    public function updateCardReport(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reportId = (int)($args['report_id'] ?? 0);

        if ($reportId < 1) {
            return $response->withJson(['error' => 'Invalid report ID'], 400);
        }

        $params = $this->parsedBody($request);

        if (array_key_exists('resolved', $params)) {
            $this->cardReports->setResolved($reportId, (bool)$params['resolved']);
        }

        return $response->withJson(['success' => true]);
    }

    // ── Readings ─────────────────────────────────────────────────

    #[OA\Get(
        path: '/admin/readings',
        summary: 'List readings (paginated)',
        tags: ['Admin · Readings'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated readings',
                content: new OA\JsonContent(type: 'object')
            ),
        ]
    )]
    public function getReadings(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = max(1, (int)($params['limit'] ?? 50));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $result = $this->readings->listAll($limit, $offset);

        // Resolve deck names for the listing.
        $deckNames = [];
        foreach ($this->decks->getAll() as $deck) {
            $deckNames[$deck->deck_id] = $deck->name;
        }

        foreach ($result['rows'] as &$row) {
            $row['deck_name'] = $deckNames[$row['deck_id']] ?? ('Deck #' . $row['deck_id']);
        }
        unset($row);

        return $response->withJson($result);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/admin/readings/{reading_id}',
        summary: 'Delete a single reading',
        tags: ['Admin · Readings'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Reading not found'),
        ]
    )]
    public function deleteReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');

        if ($reading_id === '') {
            return $response->withJson(['error' => 'Invalid reading ID'], 400);
        }

        $deleted = $this->readings->delete($reading_id);

        if (!$deleted) {
            return $response->withJson(['error' => 'Reading not found'], 404);
        }

        return $response->withStatus(204);
    }

    /**
     * Bulk delete: purge guest readings older than the given age. The window is
     * an allow-listed number of days passed as the `older_than_days` query param.
     */
    #[OA\Delete(
        path: '/admin/readings/all',
        summary: 'Purge guest readings older than a given age',
        tags: ['Admin · Readings'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(
                name: 'older_than_days',
                in: 'query',
                required: true,
                description: 'Allow-listed retention window in days',
                schema: new OA\Schema(type: 'integer', enum: [7, 14, 30, 60, 90, 180, 365])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Number of deleted readings',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'deleted', type: 'integer')]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid day range'),
        ]
    )]
    public function cleanReadings(Request $request, Response $response): Response|ResponseInterface
    {
        $days = (int)($request->getQueryParams()['older_than_days'] ?? 0);

        $allowed = [7, 14, 30, 60, 90, 180, 365];
        if (!in_array($days, $allowed, true)) {
            return $response->withJson(['error' => 'Invalid day range.'], 400);
        }

        $deleted = $this->readings->cleanGuest($days);

        return $response->withJson(['deleted' => $deleted]);
    }

    // ── Deck Systems ─────────────────────────────────────────────

    #[OA\Get(
        path: '/admin/deck-systems',
        summary: 'List all approved deck systems',
        tags: ['Admin · Deck Systems'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of deck systems',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/DeckSystem'))
            ),
        ]
    )]
    public function getDeckSystems(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->deckSystems->getApproved());
    }

    #[OA\Get(
        path: '/admin/deck-systems/pending',
        summary: 'List deck systems awaiting approval',
        tags: ['Admin · Deck Systems'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of pending deck systems',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/DeckSystem'))
            ),
        ]
    )]
    public function getPendingDeckSystems(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->deckSystems->getPending());
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Get(
        path: '/admin/deck-systems/{id}',
        summary: 'Get a single deck system with its cards',
        tags: ['Admin · Deck Systems'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Deck system',
                content: new OA\JsonContent(ref: '#/components/schemas/DeckSystem')
            ),
            new OA\Response(response: 404, description: 'Deck system not found'),
        ]
    )]
    public function getDeckSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        $system = $this->deckSystems->get($id);

        if (!($system instanceof DeckSystem)) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        $cards = $this->deckSystems->getCards($id);

        $data = $system->jsonSerialize();
        $data['cards'] = array_map(fn($c) => $c->jsonSerialize(), $cards);

        return $response->withJson($data);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/admin/deck-systems/{id}/approve',
        summary: 'Approve a pending deck system',
        tags: ['Admin · Deck Systems'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Approved deck system',
                content: new OA\JsonContent(ref: '#/components/schemas/DeckSystem')
            ),
            new OA\Response(response: 404, description: 'Deck system not found'),
        ]
    )]
    public function approveDeckSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);

        $system = $this->deckSystems->update($id, ['approved' => true]);

        if ($system === null) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        return $response->withJson($system);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Put(
        path: '/admin/deck-systems/{id}',
        summary: 'Update a deck system and its cards',
        tags: ['Admin · Deck Systems'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'short_name', type: 'string'),
                    new OA\Property(property: 'total_cards', type: 'integer'),
                    new OA\Property(property: 'cards', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated deck system',
                content: new OA\JsonContent(ref: '#/components/schemas/DeckSystem')
            ),
            new OA\Response(response: 404, description: 'Deck system not found'),
        ]
    )]
    public function updateDeckSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id     = (int)($args['id'] ?? 0);
        $params = $this->parsedBody($request);

        // The system must exist; but an edit that only changes card definitions
        // (no name/short_name/total_cards/approved change) is still valid, so a
        // null from update() is not on its own a 404.
        $existing = $this->deckSystems->get($id);
        if (!($existing instanceof DeckSystem)) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        $system = $this->deckSystems->update($id, $params) ?? $existing;

        // If cards were supplied, update them
        $cards = $params['cards'] ?? null;
        if (is_array($cards)) {
            $cardData = [];
            foreach ($cards as $i => $card) {
                if (!is_array($card)) {
                    continue;
                }
                $cardData[] = [
                    'card_id'           => (int)($card['card_id'] ?? ($i + 1)),
                    'name'              => trim((string)($card['name'] ?? '')),
                    'keywords'          => $card['keywords'] ?? null,
                    'meaning'           => $card['meaning'] ?? null,
                    'advice'            => $card['advice'] ?? null,
                    'reversed_keywords' => $card['reversed_keywords'] ?? null,
                    'reversed_meaning'  => $card['reversed_meaning'] ?? null,
                    'reversed_advice'   => $card['reversed_advice'] ?? null,
                ];
            }
            if (!empty($cardData)) {
                $this->deckSystems->deleteCards($id);
                $this->deckSystems->saveCards($id, $cardData);
            }
        }

        return $response->withJson($system);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/admin/deck-systems/{id}',
        summary: 'Delete a deck system',
        tags: ['Admin · Deck Systems'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Deck system not found'),
        ]
    )]
    public function deleteDeckSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);

        $result = $this->deckSystems->delete($id);

        if (!$result) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        return $response->withStatus(204);
    }
}
