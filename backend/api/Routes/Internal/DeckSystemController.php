<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\DeckSystemRepository;
use Tarot\Repository\UserRepository;
use Tarot\Structure\DeckSystem;
use Tarot\Utility\Session;

class DeckSystemController extends AbstractController
{
    public function __construct(
        private readonly DeckSystemRepository $systems,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Public: list all approved deck systems.
     */
    public function getSystems(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->systems->getApproved())
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /**
     * Public: get a single deck system with its cards.
     *
     * @param array<string,string> $args
     */
    public function getSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);

        $system = $this->systems->get($id);
        if (!($system instanceof DeckSystem)) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        $cards = $this->systems->getCards($id);

        $data = $system->jsonSerialize();
        $data['cards'] = array_map(fn($c) => $c->jsonSerialize(), $cards);

        return $response->withJson($data)
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /**
     * Submit a new deck system (requires auth). If admin, auto-approved.
     */
    public function submitSystem(Request $request, Response $response): Response|ResponseInterface
    {
        Session::start();

        $userId = Session::userId();
        if ($userId === null) {
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        // Admin status is a DB-backed fact (re-checked each request, as the
        // AdminAuth middleware does) — admins' submissions are auto-approved.
        $user    = $this->users->findById($userId);
        $isAdmin = $user !== null && $user->isActive() && $user->isAdmin();
        $params  = $request->getParsedBody() ?? [];

        $name      = trim((string)($params['name'] ?? ''));
        $shortName = trim((string)($params['short_name'] ?? ''));
        $totalCards = (int)($params['total_cards'] ?? 78);

        if ($name === '' || $shortName === '') {
            return $response->withJson(['error' => 'Name and Short Name are required.'], 400);
        }

        if ($totalCards < 1) {
            return $response->withJson(['error' => 'Total cards must be at least 1.'], 400);
        }

        $cards = $params['cards'] ?? [];
        if (is_string($cards)) {
            $decoded = json_decode($cards, true);
            $cards = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($cards) || count($cards) < $totalCards) {
            return $response->withJson(['error' => "At least $totalCards card entries are required (name is mandatory for each)."], 400);
        }

        // Validate that each card has at least a name
        foreach ($cards as $i => $card) {
            if (!is_array($card) || trim((string)($card['name'] ?? '')) === '') {
                return $response->withJson(['error' => "Card #" . ($i + 1) . " requires a name."], 400);
            }
        }

        $system = $this->systems->create([
            'name'         => $name,
            'short_name'   => $shortName,
            'total_cards'  => $totalCards,
            'approved'     => $isAdmin,
            'submitted_by' => $userId,
        ]);

        if ($system === null) {
            return $response->withJson(['error' => 'Failed to create deck system. Name or short name may already be in use.'], 500);
        }

        // Store the cards
        $cardData = [];
        foreach ($cards as $i => $card) {
            $cardData[] = [
                'card_id'           => $i + 1,
                'name'              => trim((string)($card['name'] ?? '')),
                'keywords'          => $card['keywords'] ?? null,
                'meaning'           => $card['meaning'] ?? null,
                'advice'            => $card['advice'] ?? null,
                'reversed_keywords' => $card['reversed_keywords'] ?? null,
                'reversed_meaning'  => $card['reversed_meaning'] ?? null,
                'reversed_advice'   => $card['reversed_advice'] ?? null,
            ];
        }

        $this->systems->saveCards($system->getDeckSystemId(), $cardData);

        return $response->withJson($system, 201);
    }
}
