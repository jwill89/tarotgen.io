<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use JsonException;
use Tarot\Exception\ApiException;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\ReadingService;
use Tarot\Structure\Reading;
use Tarot\Utility\Input;
use Tarot\Utility\Session;

class ReadingController extends AbstractController
{
    public function __construct(
        private readonly ReadingRepository $readings,
        private readonly ReadingService $readingService,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Get(
        path: '/readings/{reading_id}',
        summary: 'Fetch a reading (password-protected readings return a locked stub to non-owners)',
        tags: ['Readings'],
        parameters: [
            new OA\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The reading, or a { locked, reading_name } stub',
                content: new OA\JsonContent(ref: '#/components/schemas/Reading')
            ),
            new OA\Response(response: 404, description: 'InvalidReadingID'),
        ]
    )]
    public function getReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        if (!($reading instanceof Reading)) {
            return $response->withJson(['error' => 'InvalidReadingID'], 404);
        }

        Session::start();
        $viewerId = Session::userId() ?? 0;
        $isOwner  = $reading->user_id !== null && $reading->user_id === $viewerId;

        // Password gate: anyone who isn't the owner and hasn't already unlocked
        // this reading in their session gets only the locked stub.
        if ($reading->password_protected && !$isOwner && empty($_SESSION['unlocked_readings'][$reading_id])) {
            return $response
                ->withJson(['locked' => true, 'reading_name' => $reading->reading_name])
                ->withHeader('Cache-Control', 'private, no-store');
        }

        return $response
            ->withJson($this->accessiblePayload($reading, $isOwner))
            // Response depends on the viewer's session now, so it must not be cached.
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/readings/{reading_id}/unlock',
        summary: 'Unlock a password-protected reading for this session',
        tags: ['Readings'],
        parameters: [
            new OA\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['password'],
                properties: [new OA\Property(property: 'password', type: 'string')]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The unlocked reading',
                content: new OA\JsonContent(ref: '#/components/schemas/Reading')
            ),
            new OA\Response(response: 401, description: 'Incorrect password'),
            new OA\Response(response: 404, description: 'InvalidReadingID'),
        ]
    )]
    public function unlockReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        if (!($reading instanceof Reading)) {
            return $response->withJson(['error' => 'InvalidReadingID'], 404);
        }

        Session::start();
        $viewerId = Session::userId() ?? 0;
        $isOwner  = $reading->user_id !== null && $reading->user_id === $viewerId;

        if (!$reading->password_protected || $isOwner) {
            // Nothing to unlock — just return the reading.
            return $response->withJson($this->accessiblePayload($reading, $isOwner))
                ->withHeader('Cache-Control', 'private, no-store');
        }

        $password = (string)(($this->parsedBody($request))['password'] ?? '');
        if (!$this->readings->verifyPassword($reading_id, $password)) {
            return $response->withJson(['error' => 'Incorrect password.'], 401);
        }

        // Remember the unlock for this session so refreshes keep working.
        $_SESSION['unlocked_readings'][$reading_id] = true;

        return $response->withJson($this->accessiblePayload($reading, $isOwner))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/readings/generate',
        summary: 'Generate a random reading from a draw spec',
        tags: ['Readings'],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Draw spec: deck, spread or card count, and options',
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'The created reading',
                content: new OA\JsonContent(ref: '#/components/schemas/Reading')
            ),
            new OA\Response(response: 400, description: 'Invalid draw spec'),
        ]
    )]
    public function newReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        try {
            $reading = $this->readingService->generate($this->parsedBody($request), $this->currentUserId());
            return $response->withJson($reading, 201);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Post(
        path: '/readings',
        summary: 'Create a custom reading from explicitly chosen cards/positions',
        tags: ['Readings'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'The created reading',
                content: new OA\JsonContent(ref: '#/components/schemas/Reading')
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
        ]
    )]
    public function customReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        try {
            $reading = $this->readingService->createCustom($this->parsedBody($request), $this->currentUserId());
            return $response->withJson($reading, 201);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * Update a reading's spread/placement information after a "Free Draw With Placement"
     * has been generated. The caller provides position data which is merged into the
     * existing reading_info JSON as a spread snapshot.
     *
     * @param array<string,string> $args
     * @throws JsonException
     */
    #[OA\Put(
        path: '/readings/{reading_id}/placement',
        summary: 'Save spread/placement positions onto an existing reading',
        tags: ['Readings'],
        parameters: [
            new OA\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['positions'],
                properties: [
                    new OA\Property(property: 'positions', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'spread_name', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Saved',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'reading_id', type: 'string'),
                ])
            ),
            new OA\Response(response: 400, description: 'Position count mismatch or invalid data'),
            new OA\Response(response: 403, description: 'Not the owner'),
            new OA\Response(response: 404, description: 'Reading not found'),
            new OA\Response(response: 409, description: 'Reading is final'),
        ]
    )]
    public function updatePlacement(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        if (!($reading instanceof Reading)) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        // Allow guests (by reading_id alone) or the owner — placement is done
        // immediately after generation, before the user navigates away.
        $userId = $this->currentUserId();
        $isOwner = $reading->user_id !== null && $reading->user_id === $userId;
        $isGuest = $reading->user_id === null;

        if (!$isOwner && !$isGuest) {
            return $response->withJson(['error' => 'You do not own this reading.'], 403);
        }

        if ($reading->is_final) {
            return $response->withJson(['error' => 'This reading is final and can no longer be changed.'], 409);
        }

        $params = $this->parsedBody($request);
        $positions = $params['positions'] ?? [];

        if (!is_array($positions) || count($positions) === 0) {
            return $response->withJson(['error' => 'At least one position is required.'], 400);
        }

        // Decode existing reading_info.
        $info = json_decode($reading->reading_info, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            return $response->withJson(['error' => 'Invalid reading data.'], 500);
        }

        $drawCount = count($info['draw'] ?? []);
        if (count($positions) !== $drawCount) {
            return $response->withJson(['error' => 'Position count must match the number of drawn cards.'], 400);
        }

        // Build spread snapshot from the placement data. When the reading
        // already had a spread (e.g. drawing more cards into an existing spread),
        // preserve its identity — only the positions are being rewritten.
        $existingSpread = is_array($info['spread'] ?? null) ? $info['spread'] : null;
        $spreadName = Input::string($params['spread_name'] ?? null, 100);
        if ($spreadName === '') {
            $spreadName = (string)($existingSpread['name'] ?? '') ?: 'Free Draw Placement';
        }
        $snappedPositions = [];

        foreach ($positions as $idx => $pos) {
            if (!is_array($pos)) {
                return $response->withJson(['error' => 'Invalid position data.'], 400);
            }

            $rotation = (int)($pos['rotation'] ?? 0) % 360;
            if ($rotation < 0) {
                $rotation += 360;
            }

            $snappedPositions[] = [
                'order'    => $idx + 1,
                'title'    => Input::string($pos['title'] ?? null, 100),
                'x'        => max(0.0, min(100.0, round((float)($pos['x'] ?? 50), 2))),
                'y'        => max(0.0, min(100.0, round((float)($pos['y'] ?? 50), 2))),
                'rotation' => $rotation,
            ];
        }

        $info['spread'] = [
            'spread_id'   => (int)($existingSpread['spread_id'] ?? 0),
            'name'        => $spreadName,
            'description' => (string)($existingSpread['description'] ?? ''),
            'positions'   => $snappedPositions,
        ];

        $updatedJson = json_encode($info, JSON_THROW_ON_ERROR);
        $ownerId = $isOwner ? $userId : null;
        $updated = $this->readings->updateReadingInfo($reading_id, $updatedJson, $ownerId);

        if (!$updated) {
            return $response->withJson(['error' => 'Failed to save placement.'], 500);
        }

        return $response->withJson(['success' => true, 'reading_id' => $reading_id]);
    }

    /**
     * Draw additional cards into an existing generated reading owned by the
     * caller. The new cards are appended to the draw; for a spread reading the
     * client then opens the placement editor to position them.
     *
     * @param array<string,string> $args
     * @throws JsonException
     */
    #[OA\Post(
        path: '/readings/{reading_id}/draw',
        summary: 'Draw additional cards into a non-final, non-custom reading you own',
        tags: ['Readings'],
        parameters: [
            new OA\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The updated reading',
                content: new OA\JsonContent(ref: '#/components/schemas/Reading')
            ),
            new OA\Response(response: 400, description: 'Custom readings cannot draw'),
            new OA\Response(response: 403, description: 'Not the owner'),
            new OA\Response(response: 404, description: 'Reading not found'),
            new OA\Response(response: 409, description: 'Reading is final'),
        ]
    )]
    public function drawCards(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        if (!($reading instanceof Reading)) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        $userId = $this->currentUserId();
        if ($userId === null || $reading->user_id !== $userId) {
            return $response->withJson(['error' => 'You do not own this reading.'], 403);
        }

        if ($reading->is_final) {
            return $response->withJson(['error' => 'This reading is final and can no longer be changed.'], 409);
        }

        $info = json_decode($reading->reading_info, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            return $response->withJson(['error' => 'Invalid reading data.'], 500);
        }

        if (($info['origin'] ?? null) === 'custom') {
            return $response->withJson(['error' => 'Custom readings cannot draw additional cards.'], 400);
        }

        try {
            $updated = $this->readingService->drawAdditional($info, $this->parsedBody($request));
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }

        $saved = $this->readings->updateReadingInfo(
            $reading_id,
            json_encode($updated, JSON_THROW_ON_ERROR),
            $userId
        );

        if (!($saved instanceof Reading)) {
            return $response->withJson(['error' => 'Failed to save the new cards.'], 500);
        }

        return $response->withJson($this->accessiblePayload($saved, true))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Mark a reading as final, permanently locking it against further draws.
     * One-way: there is no endpoint to undo it. Owner-only.
     *
     * @param array<string,string> $args
     * @throws JsonException
     */
    #[OA\Post(
        path: '/readings/{reading_id}/finalize',
        summary: 'Permanently lock a reading against further draws (owner-only, one-way)',
        tags: ['Readings'],
        parameters: [
            new OA\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The finalized reading',
                content: new OA\JsonContent(ref: '#/components/schemas/Reading')
            ),
            new OA\Response(response: 403, description: 'Not the owner'),
            new OA\Response(response: 404, description: 'Reading not found'),
        ]
    )]
    public function finalizeReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        if (!($reading instanceof Reading)) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        $userId = $this->currentUserId();
        if ($userId === null || $reading->user_id !== $userId) {
            return $response->withJson(['error' => 'You do not own this reading.'], 403);
        }

        $updated = $this->readings->markFinal($reading_id, $userId);
        if (!($updated instanceof Reading)) {
            return $response->withJson(['error' => 'Failed to finalize the reading.'], 500);
        }

        return $response->withJson($this->accessiblePayload($updated, true))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Build the full reading payload for a viewer allowed to see it, including
     * the resolved author label (respecting hide_user) and whether the viewer
     * is the owner.
     *
     * @return array<string,mixed>
     * @throws JsonException
     */
    private function accessiblePayload(Reading $reading, bool $isOwner): array
    {
        // Decode reading_info so it's sent as a proper JSON object, not a string.
        $info = json_decode($reading->reading_info, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            $info = [];
        }

        // Only the owner of a randomly-generated (non-custom) reading that hasn't
        // been finalized may draw additional cards into it.
        $origin      = $info['origin'] ?? null;
        $canDrawMore = $isOwner && !$reading->is_final && $origin !== 'custom';

        return [
            'reading_id'    => $reading->reading_id,
            'reading_info'  => $info,
            'reading_time'  => $reading->reading_time,
            'reading_name'  => $reading->reading_name,
            'reading_notes' => $reading->reading_notes,
            'reader'        => $this->resolveReader($reading),
            'is_owner'      => $isOwner,
            'is_final'      => $reading->is_final,
            'can_draw_more' => $canDrawMore,
            'locked'        => false,
        ];
    }

    /**
     * The author label shown in the reading details: "Guest" when there's no
     * owner or the owner hid their name, otherwise the owner's display name.
     */
    private function resolveReader(Reading $reading): string
    {
        if ($reading->user_id === null || $reading->hide_user) {
            return 'Guest';
        }

        $owner = $this->users->findById($reading->user_id);
        return $owner !== null ? $owner->display_name : 'Guest';
    }

    private function currentUserId(): ?int
    {
        Session::start();
        return Session::userId();
    }
}
