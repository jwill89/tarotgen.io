<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
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
    #[OA\Get(
        path: '/spreads',
        summary: 'List all public spreads',
        tags: ['Spreads'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of spreads',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Spread'))
            ),
        ]
    )]
    #[OA\Get(
        path: '/spreads/{spread_id}',
        summary: 'A single public spread',
        tags: ['Spreads'],
        parameters: [
            new OA\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The spread',
                content: new OA\JsonContent(ref: '#/components/schemas/Spread')
            ),
            new OA\Response(response: 404, description: 'InvalidSpreadID'),
        ]
    )]
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

    #[OA\Post(
        path: '/spreads',
        summary: 'Submit a spread into the moderation queue (rate-limited: 5/IP/hour)',
        tags: ['Spreads'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'positions'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'positions', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Accepted into the pending queue',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean')])
            ),
            new OA\Response(response: 400, description: 'Validation failure'),
            new OA\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
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
