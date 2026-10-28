<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\ChangelogRepository;

/**
 * The changelog entries shown on the public changelog page.
 */
class AdminChangelogController extends AbstractController
{
    public function __construct(
        private readonly ChangelogRepository $changelog,
    ) {
    }

    #[OA\Get(
        path: '/admin/changelog',
        summary: 'List all changelog entries',
        tags: ['Admin · Changelog'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of changelog entries',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/ChangelogEntry')
                )
            ),
        ]
    )]
    public function getChangelog(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->changelog->get());
    }

    #[OA\Post(
        path: '/admin/changelog',
        summary: 'Create a changelog entry',
        tags: ['Admin · Changelog'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'body', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created changelog entry',
                content: new OA\JsonContent(ref: '#/components/schemas/ChangelogEntry')
            ),
            new OA\Response(response: 500, description: 'Failed to create changelog entry'),
        ]
    )]
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
    #[OA\Put(
        path: '/admin/changelog/{entry_id}',
        summary: 'Update a changelog entry',
        tags: ['Admin · Changelog'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'entry_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'body', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated changelog entry',
                content: new OA\JsonContent(ref: '#/components/schemas/ChangelogEntry')
            ),
            new OA\Response(response: 404, description: 'Changelog entry not found or no valid fields'),
        ]
    )]
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
    #[OA\Delete(
        path: '/admin/changelog/{entry_id}',
        summary: 'Delete a changelog entry',
        tags: ['Admin · Changelog'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'entry_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
        ]
    )]
    public function deleteChangelogEntry(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $entry_id = (int)($args['entry_id'] ?? 0);

        $result = $this->changelog->delete($entry_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete changelog entry'], 500);
        }

        return $response->withStatus(204);
    }
}
