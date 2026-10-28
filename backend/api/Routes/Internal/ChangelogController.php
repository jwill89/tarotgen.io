<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\ChangelogRepository;
use Tarot\Structure\ChangelogEntry;
use OpenApi\Spec as OAS;

class ChangelogController extends AbstractController
{
    public function __construct(
        private readonly ChangelogRepository $changelog,
    ) {
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Get(
        path: '/changelog',
        summary: 'List changelog entries (newest first)',
        tags: ['Changelog'],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of changelog entries',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/ChangelogEntry'))
            ),
        ]
    )]
    #[OAS\Operation\Get(
        path: '/changelog/{entry_id}',
        summary: 'A single changelog entry',
        tags: ['Changelog'],
        parameters: [
            new OAS\Parameter(name: 'entry_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The changelog entry',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/ChangelogEntry')
            ),
            new OAS\Response(response: 404, description: 'InvalidEntryID'),
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
