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

    /**
     * @param array<string,string> $args
     */
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

    /**
     * @param array<string,string> $args
     */
    public function newReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        try {
            $reading = $this->readingService->generate($request->getParsedBody() ?? [], $this->currentUserId());
            return $response->withJson($reading);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * @param array<string,string> $args
     */
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
     *
     * @param array<string,string> $args
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

        if ($reading->isFinal()) {
            return $response->withJson(['error' => 'This reading is final and can no longer be changed.'], 409);
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
            if ($rotation < 0) $rotation += 360;

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
    public function drawCards(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        if (!($reading instanceof Reading)) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        $userId = $this->currentUserId();
        if ($userId === null || $reading->getUserId() !== $userId) {
            return $response->withJson(['error' => 'You do not own this reading.'], 403);
        }

        if ($reading->isFinal()) {
            return $response->withJson(['error' => 'This reading is final and can no longer be changed.'], 409);
        }

        $info = json_decode($reading->getReadingInfo(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            return $response->withJson(['error' => 'Invalid reading data.'], 500);
        }

        if (($info['origin'] ?? null) === 'custom') {
            return $response->withJson(['error' => 'Custom readings cannot draw additional cards.'], 400);
        }

        try {
            $updated = $this->readingService->drawAdditional($info, $request->getParsedBody() ?? []);
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
    public function finalizeReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        if (!($reading instanceof Reading)) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        $userId = $this->currentUserId();
        if ($userId === null || $reading->getUserId() !== $userId) {
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
        $info = json_decode($reading->getReadingInfo(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            $info = [];
        }

        // Only the owner of a randomly-generated (non-custom) reading that hasn't
        // been finalized may draw additional cards into it.
        $origin      = $info['origin'] ?? null;
        $canDrawMore = $isOwner && !$reading->isFinal() && $origin !== 'custom';

        return [
            'reading_id'    => $reading->getReadingId(),
            'reading_info'  => $info,
            'reading_time'  => $reading->getReadingTime(),
            'reading_name'  => $reading->getReadingName(),
            'reading_notes' => $reading->getReadingNotes(),
            'reader'        => $this->resolveReader($reading),
            'is_owner'      => $isOwner,
            'is_final'      => $reading->isFinal(),
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
