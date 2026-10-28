<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\FavoriteSpreadRepository;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\SpreadRepository;
use OpenApi\Spec as OAS;

/**
 * Public spreads, and the user submissions waiting to become one.
 */
class AdminSpreadController extends AbstractController
{
    public function __construct(
        private readonly SpreadRepository $spreads,
        private readonly PendingSpreadRepository $pendingSpreads,
        private readonly FavoriteSpreadRepository $favorites,
    ) {
    }

    #[OAS\Operation\Get(
        path: '/admin/spreads',
        summary: 'List all public spreads',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of spreads',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Spread'))
            ),
        ]
    )]
    public function getSpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->spreads->get());
    }

    #[OAS\Operation\Post(
        path: '/admin/spreads',
        summary: 'Create a public spread',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'positions', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition'))),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Created spread',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Spread')
            ),
            new OAS\Response(response: 500, description: 'Failed to create spread'),
        ]
    )]
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
    #[OAS\Operation\Put(
        path: '/admin/spreads/{spread_id}',
        summary: 'Update a public spread',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'positions', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition'))),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated spread',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Spread')
            ),
            new OAS\Response(response: 404, description: 'Spread not found or no valid fields'),
        ]
    )]
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
    #[OAS\Operation\Delete(
        path: '/admin/spreads/{spread_id}',
        summary: 'Delete a public spread',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
        ]
    )]
    public function deleteSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spread_id = (int)($args['spread_id'] ?? 0);

        $result = $this->spreads->delete($spread_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete spread'], 500);
        }

        // Remove any user favorites pointing to this deleted public spread.
        $this->favorites->removeBySpread('public', $spread_id);

        return $response->withStatus(204);
    }

    // ── Pending Spreads (user submissions) ──────────────────────

    #[OAS\Operation\Get(
        path: '/admin/pending-spreads',
        summary: 'List pending spread submissions',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Pending spread submissions',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/PendingSpread'))
            ),
        ]
    )]
    public function getPendingSpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->pendingSpreads->get());
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/pending-spreads/{pending_id}/approve',
        summary: 'Approve a pending spread submission',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'pending_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Approved spread',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Spread')
            ),
            new OAS\Response(response: 500, description: 'Failed to approve spread'),
        ]
    )]
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
    #[OAS\Operation\Delete(
        path: '/admin/pending-spreads/{pending_id}',
        summary: 'Reject a pending spread submission',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'pending_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Rejected'),
            new OAS\Response(response: 500, description: 'Failed to reject spread'),
        ]
    )]
    public function rejectPendingSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $pending_id = (int)($args['pending_id'] ?? 0);

        $result = $this->pendingSpreads->reject($pending_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to reject spread'], 500);
        }

        return $response->withStatus(204);
    }
}
