<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Config\Env;
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
        private readonly ReadingRepository $readings,
        private readonly StatsService $stats,
        private readonly ThumbnailService $thumbnails,
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly FavoriteSpreadRepository $favorites,
    ) {
    }

    // ── Dashboard stats ─────────────────────────────────────────

    public function getStats(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->stats->overview());
    }

    /**
     * One-shot dashboard payload: entity counts (cheap COUNT(*) queries) plus the
     * usage stats overview — so the dashboard loads with a single request.
     */
    public function getSummary(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson([
            'counts' => $this->stats->counts(),
            'stats'  => $this->stats->overview(),
        ]);
    }

    // ── Authentication ──────────────────────────────────────────
    // Admin access is gated by AdminAuth (an active is_admin user account);
    // logging in/out is handled by the regular user endpoints (/api/user/*).

    public function checkAuth(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson(['authenticated' => true]);
    }


    // ── Decks ───────────────────────────────────────────────────

    public function getDecks(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->decks->getApproved());
    }

    public function getPendingDecks(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->decks->getPending());
    }

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
    public function approveDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);

        $deck = $this->decks->update($deck_id, ['approved' => true]);

        if ($deck === null) {
            return $response->withJson(['error' => 'Deck not found'], 404);
        }

        return $response->withJson($deck);
    }

    /**
     * @param array<string,string> $args
     */
    public function markDeckUsable(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);
        $params  = $this->parsedBody($request);
        $usable  = (bool)($params['usable'] ?? true);

        $deck = $this->decks->update($deck_id, ['usable' => $usable]);

        if ($deck === null) {
            return $response->withJson(['error' => 'Deck not found'], 404);
        }

        // Create the on-disk image folders when marking a deck usable.
        if ($usable) {
            $this->thumbnails->ensureDeckFolders($deck->deck_id);
        }

        return $response->withJson($deck);
    }

    // ── Thumbnails ──────────────────────────────────────────────

    /**
     * @param array<string,string> $args
     */
    public function generateDeckThumbnails(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        @set_time_limit(0);

        $deck_id = (int)($args['deck_id'] ?? 0);
        if ($deck_id < 1) {
            return $response->withJson(['error' => 'Invalid deck ID'], 400);
        }

        return $response->withJson($this->thumbnails->generateForDeck($deck_id));
    }

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
    public function updateDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);
        $params = $this->parsedBody($request);

        $deck = $this->decks->update($deck_id, $params);

        if ($deck === null) {
            return $response->withJson(['error' => 'Deck not found or no valid fields'], 404);
        }

        return $response->withJson($deck);
    }

    /**
     * @param array<string,string> $args
     */
    public function deleteDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);

        $result = $this->decks->delete($deck_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete deck'], 500);
        }

        return $response->withJson(['success' => true]);
    }

    // ── Special Cards ───────────────────────────────────────────

    public function getSpecialCards(Request $request, Response $response): Response|ResponseInterface
    {
        $deck_id = $request->getQueryParams()['deck_id'] ?? null;
        $deck_id = $deck_id !== null ? (int)$deck_id : null;

        return $response->withJson($this->specialCards->getAll($deck_id));
    }

    public function createSpecialCard(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        $card = $this->specialCards->create($params);

        if ($card === null) {
            return $response->withJson(['error' => 'Failed to create special card'], 500);
        }

        return $response->withJson($card, 201);
    }

    /**
     * @param array<string,string> $args
     */
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
    public function deleteSpecialCard(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int)($args['deck_id'] ?? 0);
        $card_id = (int)($args['card_id'] ?? 0);

        $result = $this->specialCards->delete($deck_id, $card_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete special card'], 500);
        }

        return $response->withJson(['success' => true]);
    }

    // ── Spreads ─────────────────────────────────────────────────

    public function getSpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->spreads->get());
    }

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
    public function deleteSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spread_id = (int)($args['spread_id'] ?? 0);

        $result = $this->spreads->delete($spread_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete spread'], 500);
        }

        // Remove any user favorites pointing to this deleted public spread.
        $this->favorites->removeBySpread('public', $spread_id);

        return $response->withJson(['success' => true]);
    }

    // ── Pending Spreads (user submissions) ──────────────────────

    public function getPendingSpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->pendingSpreads->get());
    }

    /**
     * @param array<string,string> $args
     */
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
    public function rejectPendingSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $pending_id = (int)($args['pending_id'] ?? 0);

        $result = $this->pendingSpreads->reject($pending_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to reject spread'], 500);
        }

        return $response->withJson(['success' => true]);
    }

    // ── Changelog ───────────────────────────────────────────────

    public function getChangelog(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->changelog->get());
    }

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
    public function deleteChangelogEntry(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $entry_id = (int)($args['entry_id'] ?? 0);

        $result = $this->changelog->delete($entry_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete changelog entry'], 500);
        }

        return $response->withJson(['success' => true]);
    }

    // ── Users ───────────────────────────────────────────────────

    public function getUsers(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->users->getAll());
    }

    /**
     * @param array<string,string> $args
     */
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
     * @param array<string,string> $args
     */
    public function setUserAdmin(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        if ($user_id < 1 || $this->users->findById($user_id) === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $params  = $this->parsedBody($request);
        $isAdmin = (bool)($params['is_admin'] ?? false);

        $this->users->setAdmin($user_id, $isAdmin);

        // Return the refreshed user so the client can update the row in place.
        return $response->withJson($this->users->findById($user_id));
    }

    /**
     * @param array<string,string> $args
     */
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
    public function deleteUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        if ($user_id < 1 || $this->users->findById($user_id) === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $this->users->delete($user_id);

        return $response->withJson(['success' => true]);
    }

    // ── Contacts ─────────────────────────────────────────────────

    public function getContacts(Request $request, Response $response): Response|ResponseInterface
    {
        $showRead = ($request->getQueryParams()['show_read'] ?? '0') === '1';
        $unreadOnly = $showRead ? null : true;

        return $response->withJson($this->contacts->get($unreadOnly));
    }

    /**
     * @param array<string,string> $args
     */
    public function markContactRead(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $contact_id = (int)($args['contact_id'] ?? 0);
        $params = $this->parsedBody($request);
        $read = (bool)($params['is_read'] ?? true);

        if ($contact_id < 1) {
            return $response->withJson(['error' => 'Invalid contact ID'], 400);
        }

        $this->contacts->markRead($contact_id, $read);

        return $response->withJson(['success' => true]);
    }

    // ── Readings ─────────────────────────────────────────────────

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

        return $response->withJson(['success' => true]);
    }

    public function cleanReadings(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        $days   = (int)($params['days'] ?? 0);

        $allowed = [7, 14, 30, 60, 90, 180, 365];
        if (!in_array($days, $allowed, true)) {
            return $response->withJson(['error' => 'Invalid day range.'], 400);
        }

        $deleted = $this->readings->cleanGuest($days);

        return $response->withJson(['success' => true, 'deleted' => $deleted]);
    }

    // ── Deck Systems ─────────────────────────────────────────────

    public function getDeckSystems(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->deckSystems->getApproved());
    }

    public function getPendingDeckSystems(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->deckSystems->getPending());
    }

    /**
     * @param array<string,string> $args
     */
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
    public function deleteDeckSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);

        $result = $this->deckSystems->delete($id);

        if (!$result) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        return $response->withJson(['success' => true]);
    }
}
