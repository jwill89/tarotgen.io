<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\DeckRepository;
use Tarot\Service\CardNameResolver;
use Tarot\Structure\Deck;
use Tarot\Utility\Session;

class DeckController extends AbstractController
{
    private DeckRepository $decks;
    private CardNameResolver $cardNames;

    public function __construct(DeckRepository $decks, CardNameResolver $cardNames)
    {
        $this->decks     = $decks;
        $this->cardNames = $cardNames;
    }

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
     */
    public function getDeckCards(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);

        $deck = $this->decks->get($deck_id);
        if (!($deck instanceof Deck)) {
            return $response->withJson(['error' => 'InvalidDeckID'], 404);
        }

        // Include extras so the user can place anything physically in the deck.
        $systemTotal = $deck->getSystemTotalCards() ?: $deck->getTotalCards();
        $available = max(1, $systemTotal + $deck->getAdditionalCards());

        $names = $this->cardNames->resolve($deck, range(1, $available)); // card_id => name
        ksort($names);

        $cards = [];
        foreach ($names as $card_id => $name) {
            $cards[] = ['card_id' => $card_id, 'name' => $name];
        }

        return $response->withJson($cards)
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

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

        $params = $request->getParsedBody() ?? [];

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
