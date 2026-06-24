<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\ChangelogRepository;
use Tarot\Structure\ChangelogEntry;

class ChangelogController extends AbstractController
{
    private ChangelogRepository $changelog;

    public function __construct(ChangelogRepository $changelog)
    {
        $this->changelog = $changelog;
    }

    /**
     * @param array<string,string> $args
     */
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
