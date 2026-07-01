<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\DeckRepository;
use Tarot\Service\CardNameResolver;
use Tarot\Structure\Deck;
use Tarot\Utility\Session;

class DeckController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $decks,
        private readonly CardNameResolver $cardNames,
    ) {
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Get(
        path: '/decks',
        summary: 'List usable decks',
        tags: ['Decks'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of usable decks',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Deck'))
            ),
        ]
    )]
    #[OA\Get(
        path: '/decks/{deck_id}',
        summary: 'A single deck',
        tags: ['Decks'],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The deck',
                content: new OA\JsonContent(ref: '#/components/schemas/Deck')
            ),
            new OA\Response(response: 404, description: 'InvalidDeckID'),
        ]
    )]
    public function getDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = $args['deck_id'] ?? null;

        $status = 200;

        if ($deck_id !== null) {
            $data = $this->decks->get((int)$deck_id);
            if (!($data instanceof Deck)) {
                $data   = ['error' => 'InvalidDeckID'];
                $status = 404;
            }
        } else {
            // Public listing: only usable decks
            $data = $this->decks->getUsable();
        }

        $response = $response->withJson($data, $status);

        // Deck metadata changes rarely; allow a short shared cache.
        if ($status === 200) {
            $response = $response->withHeader('Cache-Control', 'public, max-age=300');
        }

        return $response;
    }

    /**
     * List every card available in a deck as {card_id, name}, so the custom
     * reading screen can offer a picker. Mirrors the reading engine's card
     * resolution.
     *
     * @param array<string,string> $args
     */
    #[OA\Get(
        path: '/decks/{deck_id}/cards',
        summary: 'List every card available in a deck (for the custom-reading picker)',
        tags: ['Decks'],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of {card_id, name}',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'card_id', type: 'integer'),
                        new OA\Property(property: 'name', type: 'string'),
                    ])
                )
            ),
            new OA\Response(response: 404, description: 'InvalidDeckID'),
        ]
    )]
    public function getDeckCards(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);

        $deck = $this->decks->get($deck_id);
        if (!($deck instanceof Deck)) {
            return $response->withJson(['error' => 'InvalidDeckID'], 404);
        }

        // Include extras so the user can place anything physically in the deck.
        $systemTotal = $deck->getEffectiveTotalCards();
        $available = max(1, $systemTotal + $deck->additional_cards);

        $names = $this->cardNames->resolve($deck, range(1, $available)); // card_id => name
        ksort($names);

        $cards = [];
        foreach ($names as $card_id => $name) {
            $cards[] = ['card_id' => $card_id, 'name' => $name];
        }

        return $response->withJson($cards)
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    #[OA\Post(
        path: '/decks',
        summary: 'Submit a new deck for review (requires a signed-in user)',
        tags: ['Decks'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'artist'],
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
                description: 'The created (unapproved) deck',
                content: new OA\JsonContent(ref: '#/components/schemas/Deck')
            ),
            new OA\Response(response: 400, description: 'Name and artist are required'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    /**
     * Submit a new deck (requires a logged-in user). The deck is created
     * as unapproved (pending admin review).
     */
    public function submitDeck(Request $request, Response $response): Response|ResponseInterface
    {
        Session::start();

        $userId = Session::userId();
        if ($userId === null) {
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        $params = $this->parsedBody($request);

        $name   = trim((string)($params['name'] ?? ''));
        $artist = trim((string)($params['artist'] ?? ''));

        if ($name === '' || $artist === '') {
            return $response->withJson(['error' => 'Name and Artist are required.'], 400);
        }

        $deck = $this->decks->create([
            'name'             => $name,
            'artist'           => $artist,
            'purchase_url'     => trim((string)($params['purchase_url'] ?? '')),
            'deck_system_id'   => (int)($params['deck_system_id'] ?? 1),
            'additional_cards' => (int)($params['additional_cards'] ?? 0),
            'approved'         => false,
            'usable'           => false,
            'submitted_by'     => $userId,
        ]);

        if ($deck === null) {
            return $response->withJson(['error' => 'Failed to submit deck.'], 500);
        }

        return $response->withJson($deck, 201);
    }
}
