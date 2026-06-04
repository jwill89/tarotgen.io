<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use JsonException;
use Tarot\Exception\ApiException;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\ReadingService;
use Tarot\Structure\Reading;
use Tarot\Utility\Session;

class ReadingController extends AbstractController
{
    public function __construct(
        private readonly ReadingRepository $readings,
        private readonly ReadingService $readingService,
        private readonly UserRepository $users,
    ) {
    }

    public function getReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        if (!($reading instanceof Reading)) {
            return $response->withJson(['error' => 'InvalidReadingID'], 404);
        }

        Session::start();
        $viewerId = Session::userId() ?? 0;
        $isOwner  = $reading->getUserId() !== null && $reading->getUserId() === $viewerId;

        // Password gate: anyone who isn't the owner and hasn't already unlocked
        // this reading in their session gets only the locked stub.
        if ($reading->isPasswordProtected() && !$isOwner && empty($_SESSION['unlocked_readings'][$reading_id])) {
            return $response
                ->withJson(['locked' => true, 'reading_name' => $reading->getReadingName()])
                ->withHeader('Cache-Control', 'private, no-store');
        }

        return $response
            ->withJson($this->accessiblePayload($reading, $isOwner))
            // Response depends on the viewer's session now, so it must not be cached.
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function unlockReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        if (!($reading instanceof Reading)) {
            return $response->withJson(['error' => 'InvalidReadingID'], 404);
        }

        Session::start();
        $viewerId = Session::userId() ?? 0;
        $isOwner  = $reading->getUserId() !== null && $reading->getUserId() === $viewerId;

        if (!$reading->isPasswordProtected() || $isOwner) {
            // Nothing to unlock — just return the reading.
            return $response->withJson($this->accessiblePayload($reading, $isOwner))
                ->withHeader('Cache-Control', 'private, no-store');
        }

        $password = (string)(($request->getParsedBody() ?? [])['password'] ?? '');
        if (!$this->readings->verifyPassword($reading_id, $password)) {
            return $response->withJson(['error' => 'Incorrect password.'], 401);
        }

        // Remember the unlock for this session so refreshes keep working.
        $_SESSION['unlocked_readings'][$reading_id] = true;

        return $response->withJson($this->accessiblePayload($reading, $isOwner))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function newReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        try {
            $reading = $this->readingService->generate($request->getParsedBody() ?? [], $this->currentUserId());
            return $response->withJson($reading);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function customReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        try {
            $reading = $this->readingService->createCustom($request->getParsedBody() ?? [], $this->currentUserId());
            return $response->withJson($reading);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * Update a reading's spread/placement information after a "Free Draw With Placement"
     * has been generated. The caller provides position data which is merged into the
     * existing reading_info JSON as a spread snapshot.
     * @throws JsonException
     */
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
        $isOwner = $reading->getUserId() !== null && $reading->getUserId() === $userId;
        $isGuest = $reading->getUserId() === null;

        if (!$isOwner && !$isGuest) {
            return $response->withJson(['error' => 'You do not own this reading.'], 403);
        }

        $params = $request->getParsedBody() ?? [];
        $positions = $params['positions'] ?? [];

        if (!is_array($positions) || count($positions) === 0) {
            return $response->withJson(['error' => 'At least one position is required.'], 400);
        }

        // Decode existing reading_info.
        $info = json_decode($reading->getReadingInfo(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            return $response->withJson(['error' => 'Invalid reading data.'], 500);
        }

        $drawCount = count($info['draw'] ?? []);
        if (count($positions) !== $drawCount) {
            return $response->withJson(['error' => 'Position count must match the number of drawn cards.'], 400);
        }

        // Build spread snapshot from the placement data.
        $spreadName = mb_substr(trim((string)($params['spread_name'] ?? '')), 0, 100) ?: 'Free Draw Placement';
        $snappedPositions = [];

        foreach ($positions as $idx => $pos) {
            if (!is_array($pos)) {
                return $response->withJson(['error' => 'Invalid position data.'], 400);
            }

            $rotation = (int)($pos['rotation'] ?? 0) % 360;
            if ($rotation < 0) $rotation += 360;

            $snappedPositions[] = [
                'order'    => $idx + 1,
                'title'    => mb_substr(trim((string)($pos['title'] ?? '')), 0, 100),
                'x'        => max(0.0, min(100.0, round((float)($pos['x'] ?? 50), 2))),
                'y'        => max(0.0, min(100.0, round((float)($pos['y'] ?? 50), 2))),
                'rotation' => $rotation,
            ];
        }

        $info['spread'] = [
            'spread_id'   => 0,
            'name'        => $spreadName,
            'description' => '',
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
        $info = json_decode($reading->getReadingInfo(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            $info = [];
        }

        return [
            'reading_id'    => $reading->getReadingId(),
            'reading_info'  => $info,
            'reading_time'  => $reading->getReadingTime(),
            'reading_name'  => $reading->getReadingName(),
            'reading_notes' => $reading->getReadingNotes(),
            'reader'        => $this->resolveReader($reading),
            'is_owner'      => $isOwner,
            'locked'        => false,
        ];
    }

    /**
     * The author label shown in the reading details: "Guest" when there's no
     * owner or the owner hid their name, otherwise the owner's display name.
     */
    private function resolveReader(Reading $reading): string
    {
        if ($reading->getUserId() === null || $reading->isHideUser()) {
            return 'Guest';
        }

        $owner = $this->users->findById($reading->getUserId());
        return $owner?->getDisplayName() ?? 'Guest';
    }

    private function currentUserId(): ?int
    {
        Session::start();
        return Session::userId();
    }
}
