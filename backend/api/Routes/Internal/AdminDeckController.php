<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\SpecialCardRepository;
use Tarot\Service\CardImageService;

/**
 * Decks: the approved list and pending submissions, create/approve/edit/delete,
 * the web images derived from each deck's scans, and the special (extra) cards
 * that belong to a deck.
 */
class AdminDeckController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $decks,
        private readonly SpecialCardRepository $specialCards,
        private readonly CardImageService $cardImages,
    ) {
    }

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

    // ── Card web images (display WebP + thumbnail, derived from the PNG scans) ──

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/admin/decks/{deck_id}/images',
        summary: 'Generate missing or stale web images (display WebP + thumbnail) for a deck',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Per-card counts: generated, skipped (already current), failed',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'deck_id', type: 'integer'),
                    new OA\Property(property: 'generated', type: 'integer'),
                    new OA\Property(property: 'skipped', type: 'integer'),
                    new OA\Property(property: 'failed', type: 'integer'),
                ])
            ),
            new OA\Response(response: 400, description: 'Invalid deck ID'),
        ]
    )]
    public function generateDeckImages(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        // Full-size WebP encoding is ~0.3 s per card; a whole deck outlives the
        // default 30 s limit, and a closed tab mustn't abort it half-way.
        @set_time_limit(0);
        ignore_user_abort(true);

        $deck_id = (int)($args['deck_id'] ?? 0);
        if ($deck_id < 1) {
            return $response->withJson(['error' => 'Invalid deck ID'], 400);
        }

        return $response->withJson($this->cardImages->generateForDeck($deck_id));
    }

    #[OA\Post(
        path: '/admin/decks/images',
        summary: 'Generate missing or stale web images for all decks',
        tags: ['Admin · Decks'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Per-card totals across decks: generated, skipped (already current), failed',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'decks', type: 'integer'),
                    new OA\Property(property: 'generated', type: 'integer'),
                    new OA\Property(property: 'skipped', type: 'integer'),
                    new OA\Property(property: 'failed', type: 'integer'),
                ])
            ),
        ]
    )]
    public function generateAllImages(Request $request, Response $response): Response|ResponseInterface
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        $ids = [];
        foreach ($this->decks->getAll() as $deck) {
            $ids[] = $deck->deck_id;
        }

        return $response->withJson($this->cardImages->generateAll($ids));
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
            $this->cardImages->ensureDeckFolders($deck->deck_id);
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
}
