<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
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
use Tarot\Utility\Input;
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

    #[OA\Get(
        path: '/account/readings',
        summary: "The current user's readings, newest first",
        tags: ['Account'],
        security: [['sessionCookie' => []], ['pluginToken' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of readings',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Reading'))
            ),
        ]
    )]
    /** All of the current user's readings, newest first. */
    public function myReadings(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->readings->listByUser($this->userId($request)));
    }

    /**
     * Update a reading the current user owns: name, hidden author, view password.
     *
     * @param array<string,string> $args
     */
    #[OA\Patch(
        path: '/account/readings/{reading_id}',
        summary: "Update a reading's name, notes, hidden-author flag, or view password",
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'reading_name', type: 'string', nullable: true),
                new OA\Property(property: 'reading_notes', type: 'string', nullable: true),
                new OA\Property(property: 'hide_user', type: 'boolean'),
                new OA\Property(property: 'password', type: 'string'),
                new OA\Property(property: 'remove_password', type: 'boolean'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The updated reading',
                content: new OA\JsonContent(ref: '#/components/schemas/Reading')
            ),
            new OA\Response(response: 400, description: 'No changes provided'),
            new OA\Response(response: 404, description: 'Reading not found'),
            new OA\Response(response: 422, description: 'Password too short'),
        ]
    )]
    public function updateReadingMeta(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $body       = $this->parsedBody($request);
        $fields     = [];

        if (array_key_exists('reading_name', $body)) {
            $fields['reading_name'] = Input::nullableString($body['reading_name'], 100);
        }

        if (array_key_exists('reading_notes', $body)) {
            // Notes keep internal whitespace (only the empty check is trimmed).
            $fields['reading_notes'] = Input::nullableString($body['reading_notes'], 20000, trim: false);
        }

        if (array_key_exists('hide_user', $body)) {
            $fields['hide_user'] = Input::bool($body['hide_user']);
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

        $updated = $this->readings->updateMeta($reading_id, $this->userId($request), $fields);
        if ($updated === null) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        return $response->withJson($updated);
    }

    /**
     * Delete one of the current user's readings.
     *
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/account/readings/{reading_id}',
        summary: 'Delete one of the user\'s readings',
        tags: ['Account'],
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

        if (!$this->readings->deleteOwned($reading_id, $this->userId($request))) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        return $response->withStatus(204);
    }

    /**
     * Partial update of the current account's profile. Currently the only
     * editable field is the display name (previously the dedicated
     * PUT /account/display-name).
     */
    #[OA\Patch(
        path: '/account',
        summary: 'Update the account profile (currently the display name)',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [new OA\Property(property: 'display_name', type: 'string')])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The updated account',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                ])
            ),
            new OA\Response(response: 422, description: 'Policy/uniqueness failure'),
        ]
    )]
    public function updateProfile(Request $request, Response $response): Response|ResponseInterface
    {
        $body = $this->parsedBody($request);

        if (array_key_exists('display_name', $body)) {
            $result = $this->auth->changeDisplayName($this->userId($request), (string)$body['display_name']);
            if (!$result['ok']) {
                return $response->withJson(['error' => $result['error'] ?? 'Could not update display name.'], 422);
            }
        }

        return $response->withJson(['success' => true, 'user' => $this->users->findById($this->userId($request))]);
    }

    #[OA\Post(
        path: '/account/change-password',
        summary: 'Change the account password',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['current_password', 'new_password'],
                properties: [
                    new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                    new OA\Property(property: 'new_password', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password updated'),
            new OA\Response(response: 422, description: 'Wrong current password or weak new password'),
        ]
    )]
    public function changePassword(Request $request, Response $response): Response|ResponseInterface
    {
        $body    = $this->parsedBody($request);
        $current = (string)($body['current_password'] ?? '');
        $new     = (string)($body['new_password'] ?? '');

        $result = $this->auth->changePassword($this->userId($request), $current, $new);
        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not change password.'], 422);
        }

        return $response->withJson(['success' => true, 'message' => 'Your password has been updated.']);
    }

    #[OA\Delete(
        path: '/account',
        summary: 'Delete the account after re-entering the password (cascades readings)',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['password'], properties: [new OA\Property(property: 'password', type: 'string', format: 'password')])
        ),
        responses: [
            new OA\Response(response: 204, description: 'Account deleted'),
            new OA\Response(response: 403, description: 'Wrong password'),
        ]
    )]
    public function deleteAccount(Request $request, Response $response): Response|ResponseInterface
    {
        $password = (string)(($this->parsedBody($request))['password'] ?? '');

        $result = $this->auth->deleteAccount($this->userId($request), $password);
        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not delete account.'], 403);
        }

        // End the now-orphaned session.
        unset($_SESSION['user_id']);
        Session::regenerate();

        return $response->withStatus(204);
    }

    // ── User Spreads ─────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/account/spreads',
        summary: "The user's personal spreads",
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of personal spreads',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/UserSpread'))
            ),
        ]
    )]
    /** List all of the current user's personal spreads. */
    public function mySpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->userSpreads->listByUser($this->userId($request)));
    }

    #[OA\Post(
        path: '/account/spreads',
        summary: 'Create a personal spread',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'positions'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'positions', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'The created spread',
                content: new OA\JsonContent(ref: '#/components/schemas/UserSpread')
            ),
            new OA\Response(response: 400, description: 'Name and positions required'),
        ]
    )]
    /** Create a new personal spread. */
    public function createSpread(Request $request, Response $response): Response|ResponseInterface
    {
        $params    = $this->parsedBody($request);
        $name      = trim((string)($params['name'] ?? ''));
        $positions = $params['positions'] ?? [];

        if ($name === '' || !is_array($positions) || count($positions) === 0) {
            return $response->withJson(
                ['error' => 'A spread name and at least one card position are required.'],
                400
            );
        }

        $created = $this->userSpreads->create($this->userId($request), $params);

        if ($created === null) {
            return $response->withJson(['error' => 'Failed to save the spread.'], 500);
        }

        return $response->withJson($created, 201);
    }

    /**
     * Update one of the current user's personal spreads.
     *
     * @param array<string,string> $args
     */
    #[OA\Put(
        path: '/account/spreads/{user_spread_id}',
        summary: 'Update a personal spread',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'user_spread_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The updated spread',
                content: new OA\JsonContent(ref: '#/components/schemas/UserSpread')
            ),
            new OA\Response(response: 404, description: 'Spread not found'),
        ]
    )]
    public function updateSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadId = (int)($args['user_spread_id'] ?? 0);
        $params   = $this->parsedBody($request);

        $updated = $this->userSpreads->update($this->userId($request), $spreadId, $params);
        if ($updated === null) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        return $response->withJson($updated);
    }

    /**
     * Delete one of the current user's personal spreads.
     *
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/account/spreads/{user_spread_id}',
        summary: 'Delete a personal spread (and any favorites pointing to it)',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'user_spread_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Spread not found'),
        ]
    )]
    public function deleteSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadId = (int)($args['user_spread_id'] ?? 0);

        if (!$this->userSpreads->delete($this->userId($request), $spreadId)) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        // Remove any favorites pointing to this deleted personal spread.
        $this->favorites->removeBySpread('personal', $spreadId);

        return $response->withStatus(204);
    }

    /**
     * Submit one of the user's personal spreads as a public spread (copies into
     * pending queue; the personal copy remains untouched).
     *
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/account/spreads/{user_spread_id}/submit',
        summary: 'Submit a personal spread to the public pending queue',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'user_spread_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Submitted for review'),
            new OA\Response(response: 404, description: 'Spread not found'),
        ]
    )]
    public function submitSpreadAsPublic(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadId = (int)($args['user_spread_id'] ?? 0);
        $spread   = $this->userSpreads->get($this->userId($request), $spreadId);

        if ($spread === null) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        $pending = $this->pendingSpreads->create([
            'name'        => $spread->name,
            'description' => $spread->description,
            'card_count'  => $spread->card_count,
            'positions'   => $spread->positions,
        ], $this->userId($request));

        if ($pending === null) {
            return $response->withJson(['error' => 'Failed to submit the spread for review.'], 500);
        }

        return $response->withJson(['success' => true], 201);
    }

    // ── Favorite Spreads ────────────────────────────────────────────────────

    #[OA\Get(
        path: '/account/favorites',
        summary: 'Favorited spreads',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        responses: [new OA\Response(response: 200, description: 'Array of favorited spreads', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object')))]
    )]
    /** List the current user's favorited spreads. */
    public function myFavorites(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->favorites->listByUser($this->userId($request)));
    }

    #[OA\Post(
        path: '/account/favorites',
        summary: 'Add a favorite spread',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['spread_type', 'spread_id'],
                properties: [
                    new OA\Property(property: 'spread_type', type: 'string', enum: ['public', 'personal']),
                    new OA\Property(property: 'spread_id', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Added'),
            new OA\Response(response: 400, description: 'Invalid spread type or ID'),
        ]
    )]
    /** Add a spread to the current user's favorites. */
    public function addFavorite(Request $request, Response $response): Response|ResponseInterface
    {
        $params     = $this->parsedBody($request);
        $spreadType = (string)($params['spread_type'] ?? '');
        $spreadId   = (int)($params['spread_id'] ?? 0);

        if (!in_array($spreadType, ['public', 'personal'], true) || $spreadId < 1) {
            return $response->withJson(['error' => 'Invalid spread type or ID.'], 400);
        }

        $this->favorites->add($this->userId($request), $spreadType, $spreadId);

        return $response->withJson(['success' => true], 201);
    }

    /**
     * Remove a spread from the current user's favorites. The favorite is
     * identified by its type and id in the path.
     *
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/account/favorites/{spread_type}/{spread_id}',
        summary: 'Remove a favorite spread',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'spread_type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['public', 'personal'])),
            new OA\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 400, description: 'Invalid spread type or ID'),
        ]
    )]
    public function removeFavorite(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadType = (string)($args['spread_type'] ?? '');
        $spreadId   = (int)($args['spread_id'] ?? 0);

        if (!in_array($spreadType, ['public', 'personal'], true) || $spreadId < 1) {
            return $response->withJson(['error' => 'Invalid spread type or ID.'], 400);
        }

        $this->favorites->remove($this->userId($request), $spreadType, $spreadId);

        return $response->withStatus(204);
    }

    // ── Favorite Decks ───────────────────────────────────────────

    #[OA\Get(
        path: '/account/favorite-decks',
        summary: 'Favorited decks',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of favorited decks',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Deck'))
            ),
        ]
    )]
    public function myFavoriteDecks(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->favoriteDecks->listByUser($this->userId($request)));
    }

    #[OA\Post(
        path: '/account/favorite-decks',
        summary: 'Add a favorite deck',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['deck_id'], properties: [new OA\Property(property: 'deck_id', type: 'integer')])
        ),
        responses: [
            new OA\Response(response: 201, description: 'Added'),
            new OA\Response(response: 400, description: 'Invalid deck ID'),
        ]
    )]
    public function addFavoriteDeck(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        $deckId = (int)($params['deck_id'] ?? 0);

        if ($deckId < 1) {
            return $response->withJson(['error' => 'Invalid deck ID.'], 400);
        }

        $this->favoriteDecks->add($this->userId($request), $deckId);

        return $response->withJson(['success' => true], 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/account/favorite-decks/{deck_id}',
        summary: 'Remove a favorite deck',
        tags: ['Account'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 400, description: 'Invalid deck ID'),
        ]
    )]
    public function removeFavoriteDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deckId = (int)($args['deck_id'] ?? 0);

        if ($deckId < 1) {
            return $response->withJson(['error' => 'Invalid deck ID.'], 400);
        }

        $this->favoriteDecks->remove($this->userId($request), $deckId);

        return $response->withStatus(204);
    }

    private function userId(Request $request): int
    {
        // Set by AccountAuth (session or plugin token) or UserAuth (session).
        $id = $request->getAttribute('auth_user_id');
        if (is_int($id) && $id > 0) {
            return $id;
        }

        Session::start();
        return Session::userId() ?? 0;
    }
}
