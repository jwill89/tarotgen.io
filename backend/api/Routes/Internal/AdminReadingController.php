<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;

/**
 * Stored readings: browsing, deleting one, and bulk-deleting old ones.
 */
class AdminReadingController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $decks,
        private readonly ReadingRepository $readings,
    ) {
    }

    #[OA\Get(
        path: '/admin/readings',
        summary: 'List readings (paginated)',
        tags: ['Admin · Readings'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated readings',
                content: new OA\JsonContent(type: 'object')
            ),
        ]
    )]
    public function getReadings(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = max(1, (int)($params['limit'] ?? 50));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $result = $this->readings->listAll($limit, $offset);

        // Resolve deck names for the listing.
        $deckNames = [];
        foreach ($this->decks->getAll() as $deck) {
            $deckNames[$deck->deck_id] = $deck->name;
        }

        foreach ($result['rows'] as &$row) {
            $row['deck_name'] = $deckNames[$row['deck_id']] ?? ('Deck #' . $row['deck_id']);
        }
        unset($row);

        return $response->withJson($result);
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/admin/readings/{reading_id}',
        summary: 'Delete a single reading',
        tags: ['Admin · Readings'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Reading not found'),
        ]
    )]
    public function deleteReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = (string)($args['reading_id'] ?? '');

        if ($reading_id === '') {
            return $response->withJson(['error' => 'Invalid reading ID'], 400);
        }

        $deleted = $this->readings->delete($reading_id);

        if (!$deleted) {
            return $response->withJson(['error' => 'Reading not found'], 404);
        }

        return $response->withStatus(204);
    }

    /**
     * Bulk delete: purge guest readings older than the given age. The window is
     * an allow-listed number of days passed as the `older_than_days` query param.
     */
    #[OA\Delete(
        path: '/admin/readings/all',
        summary: 'Purge guest readings older than a given age',
        tags: ['Admin · Readings'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(
                name: 'older_than_days',
                in: 'query',
                required: true,
                description: 'Allow-listed retention window in days',
                schema: new OA\Schema(type: 'integer', enum: [7, 14, 30, 60, 90, 180, 365])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Number of deleted readings',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'deleted', type: 'integer')]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid day range'),
        ]
    )]
    public function cleanReadings(Request $request, Response $response): Response|ResponseInterface
    {
        $days = (int)($request->getQueryParams()['older_than_days'] ?? 0);

        $allowed = [7, 14, 30, 60, 90, 180, 365];
        if (!in_array($days, $allowed, true)) {
            return $response->withJson(['error' => 'Invalid day range.'], 400);
        }

        $deleted = $this->readings->cleanGuest($days);

        return $response->withJson(['deleted' => $deleted]);
    }
}
