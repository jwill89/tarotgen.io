<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\FavoriteDeckRepository;
use Tarot\Repository\FavoriteSpreadRepository;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\UserRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Service\AuthService;
use Tarot\Utility\Session;

/**
 * A signed-in user's self-service area (behind UserAuth): their readings,
 * per-reading options, spreads, account settings, and account deletion.
 */
class AccountController extends AbstractController
{
    public function __construct(
        private readonly ReadingRepository $readings,
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly UserSpreadRepository $userSpreads,
        private readonly PendingSpreadRepository $pendingSpreads,
        private readonly FavoriteSpreadRepository $favorites,
        private readonly FavoriteDeckRepository $favoriteDecks,
    ) {
    }

    /** All of the current user's readings, newest first. */
    public function myReadings(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->readings->listByUser($this->userId()));
    }

    /** Update a reading the current user owns: name, hidden author, view password. */
    public function updateReadingMeta(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $body       = $request->getParsedBody() ?? [];
        $fields     = [];

        if (array_key_exists('reading_name', $body)) {
            $name = mb_substr(trim((string)$body['reading_name']), 0, 100);
            $fields['reading_name'] = $name !== '' ? $name : null;
        }

        if (array_key_exists('reading_notes', $body)) {
            $notes = mb_substr((string)$body['reading_notes'], 0, 20000);
            $fields['reading_notes'] = trim($notes) !== '' ? $notes : null;
        }

        if (array_key_exists('hide_user', $body)) {
            $fields['hide_user'] = filter_var($body['hide_user'], FILTER_VALIDATE_BOOLEAN);
        }

        // Password: explicit removal, or set a new one. Omit both to leave as-is.
        if (!empty($body['remove_password'])) {
            $fields['password_hash'] = null;
        } elseif (array_key_exists('password', $body) && (string)$body['password'] !== '') {
            $password = (string)$body['password'];
            if (mb_strlen($password) < 4) {
                return $response->withJson(['error' => 'Reading password must be at least 4 characters.'], 422);
            }
            $fields['password_hash'] = password_hash($password, AuthService::passwordAlgo());
        }

        if ($fields === []) {
            return $response->withJson(['error' => 'No changes provided.'], 400);
        }

        $updated = $this->readings->updateMeta($reading_id, $this->userId(), $fields);
        if ($updated === null) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        return $response->withJson($updated);
    }

    /** Delete one of the current user's readings. */
    public function deleteReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');

        if (!$this->readings->deleteOwned($reading_id, $this->userId())) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        return $response->withJson(['success' => true]);
    }

    public function changeDisplayName(Request $request, Response $response): Response|ResponseInterface
    {
        $name   = (string)(($request->getParsedBody() ?? [])['display_name'] ?? '');
        $result = $this->auth->changeDisplayName($this->userId(), $name);

        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not update display name.'], 422);
        }

        return $response->withJson(['success' => true, 'user' => $this->users->findById($this->userId())]);
    }

    public function changePassword(Request $request, Response $response): Response|ResponseInterface
    {
        $body    = $request->getParsedBody() ?? [];
        $current = (string)($body['current_password'] ?? '');
        $new     = (string)($body['new_password'] ?? '');

        $result = $this->auth->changePassword($this->userId(), $current, $new);
        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not change password.'], 422);
        }

        return $response->withJson(['success' => true, 'message' => 'Your password has been updated.']);
    }

    public function deleteAccount(Request $request, Response $response): Response|ResponseInterface
    {
        $password = (string)(($request->getParsedBody() ?? [])['password'] ?? '');

        $result = $this->auth->deleteAccount($this->userId(), $password);
        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not delete account.'], 403);
        }

        // End the now-orphaned session.
        unset($_SESSION['user_id']);
        Session::regenerate();

        return $response->withJson(['success' => true]);
    }

    // ── User Spreads ─────────────────────────────────────────────────────────

    /** List all of the current user's personal spreads. */
    public function mySpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->userSpreads->listByUser($this->userId()));
    }

    /** Create a new personal spread. */
    public function createSpread(Request $request, Response $response): Response|ResponseInterface
    {
        $params    = $request->getParsedBody() ?? [];
        $name      = trim((string)($params['name'] ?? ''));
        $positions = $params['positions'] ?? [];

        if ($name === '' || !is_array($positions) || count($positions) === 0) {
            return $response->withJson(
                ['error' => 'A spread name and at least one card position are required.'],
                400
            );
        }

        $created = $this->userSpreads->create($this->userId(), $params);

        if ($created === null) {
            return $response->withJson(['error' => 'Failed to save the spread.'], 500);
        }

        return $response->withJson($created, 201);
    }

    /** Update one of the current user's personal spreads. */
    public function updateSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadId = (int)($args['user_spread_id'] ?? 0);
        $params   = $request->getParsedBody() ?? [];

        $updated = $this->userSpreads->update($this->userId(), $spreadId, $params);
        if ($updated === null) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        return $response->withJson($updated);
    }

    /** Delete one of the current user's personal spreads. */
    public function deleteSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadId = (int)($args['user_spread_id'] ?? 0);

        if (!$this->userSpreads->delete($this->userId(), $spreadId)) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        // Remove any favorites pointing to this deleted personal spread.
        $this->favorites->removeBySpread('personal', $spreadId);

        return $response->withJson(['success' => true]);
    }

    /**
     * Submit one of the user's personal spreads as a public spread (copies into
     * pending queue; the personal copy remains untouched).
     */
    public function submitSpreadAsPublic(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadId = (int)($args['user_spread_id'] ?? 0);
        $spread   = $this->userSpreads->get($this->userId(), $spreadId);

        if ($spread === null) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        $pending = $this->pendingSpreads->create([
            'name'        => $spread->getName(),
            'description' => $spread->getDescription(),
            'card_count'  => $spread->getCardCount(),
            'positions'   => $spread->getPositions(),
        ], $this->userId());

        if ($pending === null) {
            return $response->withJson(['error' => 'Failed to submit the spread for review.'], 500);
        }

        return $response->withJson(['success' => true], 201);
    }

    // ── Favorite Spreads ────────────────────────────────────────────────────

    /** List the current user's favorited spreads. */
    public function myFavorites(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->favorites->listByUser($this->userId()));
    }

    /** Add a spread to the current user's favorites. */
    public function addFavorite(Request $request, Response $response): Response|ResponseInterface
    {
        $params     = $request->getParsedBody() ?? [];
        $spreadType = (string)($params['spread_type'] ?? '');
        $spreadId   = (int)($params['spread_id'] ?? 0);

        if (!in_array($spreadType, ['public', 'personal'], true) || $spreadId < 1) {
            return $response->withJson(['error' => 'Invalid spread type or ID.'], 400);
        }

        $this->favorites->add($this->userId(), $spreadType, $spreadId);

        return $response->withJson(['success' => true], 201);
    }

    /** Remove a spread from the current user's favorites. */
    public function removeFavorite(Request $request, Response $response): Response|ResponseInterface
    {
        $params     = $request->getParsedBody() ?? [];
        $spreadType = (string)($params['spread_type'] ?? '');
        $spreadId   = (int)($params['spread_id'] ?? 0);

        if (!in_array($spreadType, ['public', 'personal'], true) || $spreadId < 1) {
            return $response->withJson(['error' => 'Invalid spread type or ID.'], 400);
        }

        $this->favorites->remove($this->userId(), $spreadType, $spreadId);

        return $response->withJson(['success' => true]);
    }

    // ── Favorite Decks ───────────────────────────────────────────

    public function myFavoriteDecks(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->favoriteDecks->listByUser($this->userId()));
    }

    public function addFavoriteDeck(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $request->getParsedBody() ?? [];
        $deckId = (int)($params['deck_id'] ?? 0);

        if ($deckId < 1) {
            return $response->withJson(['error' => 'Invalid deck ID.'], 400);
        }

        $this->favoriteDecks->add($this->userId(), $deckId);

        return $response->withJson(['success' => true], 201);
    }

    public function removeFavoriteDeck(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $request->getParsedBody() ?? [];
        $deckId = (int)($params['deck_id'] ?? 0);

        if ($deckId < 1) {
            return $response->withJson(['error' => 'Invalid deck ID.'], 400);
        }

        $this->favoriteDecks->remove($this->userId(), $deckId);

        return $response->withJson(['success' => true]);
    }

    private function userId(): int
    {
        Session::start();
        return Session::userId() ?? 0;
    }
}
