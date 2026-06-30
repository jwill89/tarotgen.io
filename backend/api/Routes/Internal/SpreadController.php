<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Structure\Spread;
use Tarot\Utility\RateLimiter;
use Tarot\Utility\Session;

class SpreadController extends AbstractController
{
    // Public submissions: max 5 per IP per hour.
    private const int SUBMIT_MAX_PER_WINDOW = 5;
    private const int SUBMIT_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly SpreadRepository $spreads,
        private readonly PendingSpreadRepository $pending,
    ) {
    }

    /**
     * @param array<string,string> $args
     */
    public function getSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spread_id = $args['spread_id'] ?? null;

        $status = 200;
        $data   = $this->spreads->get($spread_id !== null ? (int)$spread_id : null);

        if ($spread_id !== null && !($data instanceof Spread)) {
            $data   = ['error' => 'InvalidSpreadID'];
            $status = 404;
        }

        $response = $response->withJson($data, $status);

        if ($status === 200) {
            $response = $response->withHeader('Cache-Control', 'public, max-age=300');
        }

        return $response;
    }

    /**
     * Public endpoint: accept a user-submitted spread into the pending queue.
     * Rate-limited per IP to prevent spam. Never exposes the stored row.
     */
    public function submitSpread(Request $request, Response $response): Response|ResponseInterface
    {
        $ip = $this->clientIp($request);

        $limiter = new RateLimiter('spread_submit', self::SUBMIT_MAX_PER_WINDOW, self::SUBMIT_WINDOW_SECONDS);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(
                ['error' => 'You have submitted too many spreads recently. Please try again later.'],
                429
            );
        }

        $params    = $this->parsedBody($request);
        $name      = trim((string)($params['name'] ?? ''));
        $positions = $params['positions'] ?? [];

        if ($name === '' || !is_array($positions) || count($positions) === 0) {
            return $response->withJson(
                ['error' => 'A spread name and at least one card position are required.'],
                400
            );
        }

        $created = $this->pending->create($params, $this->currentUserId());

        if ($created === null) {
            return $response->withJson(['error' => 'Failed to submit the spread. Please try again.'], 500);
        }

        // Only count successful submissions against the limit.
        $limiter->hit($ip);

        return $response->withJson(['success' => true], 201);
    }

    /** The logged-in user's id, or null for a guest submission. */
    private function currentUserId(): ?int
    {
        Session::start();
        return Session::userId();
    }
}
