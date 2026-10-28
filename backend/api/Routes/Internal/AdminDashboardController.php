<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Service\StatsService;

/**
 * The admin dashboard: usage stats, entity counts, and the session check the
 * admin pages make on load.
 */
class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly StatsService $stats,
    ) {
    }

    #[OA\Get(
        path: '/admin/stats',
        summary: 'Usage statistics',
        tags: ['Admin · Dashboard'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Usage statistics',
                content: new OA\JsonContent(type: 'object')
            ),
        ]
    )]
    public function getStats(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->stats->overview());
    }

    /**
     * One-shot dashboard payload: entity counts (cheap COUNT(*) queries) plus the
     * usage stats overview — so the dashboard loads with a single request.
     */
    #[OA\Get(
        path: '/admin/summary',
        summary: 'Dashboard counts + stats',
        tags: ['Admin · Dashboard'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dashboard counts + stats',
                content: new OA\JsonContent(type: 'object')
            ),
        ]
    )]
    public function getSummary(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson([
            'counts' => $this->stats->counts(),
            'stats'  => $this->stats->overview(),
        ]);
    }

    // ── Authentication ──────────────────────────────────────────
    // Admin access is gated by AdminAuth (an active is_admin user account);
    // logging in/out is handled by the regular auth endpoints (/api/auth/*).

    #[OA\Get(
        path: '/admin/auth-check',
        summary: 'Verify the admin session',
        tags: ['Admin · Dashboard'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Admin session is valid'),
        ]
    )]
    public function checkAuth(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson(['authenticated' => true]);
    }
}
