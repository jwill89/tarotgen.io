<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\DeckSystemRepository;
use Tarot\Structure\DeckSystem;

/**
 * Deck systems (the card lists decks follow): approved and pending, edit,
 * approve, delete.
 */
class AdminDeckSystemController extends AbstractController
{
    public function __construct(
        private readonly DeckSystemRepository $deckSystems,
    ) {
    }

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
