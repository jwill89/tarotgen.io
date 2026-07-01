<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\ChangelogRepository;
use Tarot\Structure\ChangelogEntry;

class ChangelogController extends AbstractController
{
    public function __construct(
        private readonly ChangelogRepository $changelog,
    ) {
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Get(
        path: '/changelog',
        summary: 'List changelog entries (newest first)',
        tags: ['Changelog'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of changelog entries',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ChangelogEntry'))
            ),
        ]
    )]
    #[OA\Get(
        path: '/changelog/{entry_id}',
        summary: 'A single changelog entry',
        tags: ['Changelog'],
        parameters: [
            new OA\Parameter(name: 'entry_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The changelog entry',
                content: new OA\JsonContent(ref: '#/components/schemas/ChangelogEntry')
            ),
            new OA\Response(response: 404, description: 'InvalidEntryID'),
        ]
    )]
    public function getChangelog(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $entry_id = $args['entry_id'] ?? null;

        $status = 200;
        $data   = $this->changelog->get($entry_id !== null ? (int)$entry_id : null);

        if ($entry_id !== null && !($data instanceof ChangelogEntry)) {
            $data   = ['error' => 'InvalidEntryID'];
            $status = 404;
        }

        $response = $response->withJson($data, $status);

        if ($status === 200) {
            $response = $response->withHeader('Cache-Control', 'public, max-age=300');
        }

        return $response;
    }
}
