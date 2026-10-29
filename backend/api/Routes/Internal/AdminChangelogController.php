<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\ChangelogRepository;
use OpenApi\Spec as OAS;

/**
 * The changelog entries shown on the public changelog page.
 */
class AdminChangelogController extends AbstractController
{
    private const string TITLE_REQUIRED = 'Title is required';

    public function __construct(
        private readonly ChangelogRepository $changelog,
    ) {
    }

    #[OAS\Operation\Get(
        path: '/admin/changelog',
        summary: 'List all changelog entries',
        tags: ['Admin · Changelog'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of changelog entries',
                content: new OAS\MediaType\Json(
                    type: 'array',
                    items: new OAS\Schema\Items(ref: '#/components/schemas/ChangelogEntry')
                )
            ),
        ]
    )]
    public function getChangelog(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->changelog->get());
    }

    #[OAS\Operation\Post(
        path: '/admin/changelog',
        summary: 'Create a changelog entry',
        tags: ['Admin · Changelog'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['title'],
                properties: [
                    new OAS\Property(property: 'title', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'body', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Created changelog entry',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/ChangelogEntry')
            ),
            new OAS\Response(response: 400, description: 'Title is required'),
            new OAS\Response(response: 500, description: 'Failed to create changelog entry'),
        ]
    )]
    public function createChangelogEntry(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);

        if (self::blankTitle($params['title'] ?? null)) {
            return $response->withJson(['error' => self::TITLE_REQUIRED], 400);
        }

        $entry = $this->changelog->create($params);

        if ($entry === null) {
            return $response->withJson(['error' => 'Failed to create changelog entry'], 500);
        }

        return $response->withJson($entry, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Put(
        path: '/admin/changelog/{entry_id}',
        summary: 'Update a changelog entry',
        tags: ['Admin · Changelog'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'entry_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'title', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'body', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated changelog entry',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/ChangelogEntry')
            ),
            new OAS\Response(response: 400, description: 'Title is blank'),
            new OAS\Response(response: 404, description: 'Changelog entry not found or no valid fields'),
        ]
    )]
    public function updateChangelogEntry(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $entry_id = (int)($args['entry_id'] ?? 0);
        $params = $this->parsedBody($request);

        // An edit may leave the title out (it stays as it was), but not blank it.
        if (array_key_exists('title', $params) && self::blankTitle($params['title'])) {
            return $response->withJson(['error' => self::TITLE_REQUIRED], 400);
        }

        $entry = $this->changelog->update($entry_id, $params);

        if ($entry === null) {
            return $response->withJson(['error' => 'Changelog entry not found or no valid fields'], 404);
        }

        return $response->withJson($entry);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/changelog/{entry_id}',
        summary: 'Delete a changelog entry',
        tags: ['Admin · Changelog'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'entry_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
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

    private static function blankTitle(mixed $title): bool
    {
        return trim((string)$title) === '';
    }
}
