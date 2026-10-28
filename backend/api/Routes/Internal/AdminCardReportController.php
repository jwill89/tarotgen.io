<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\CardReportRepository;

/**
 * Card image problems reported from the lightbox, and their resolution.
 */
class AdminCardReportController extends AbstractController
{
    public function __construct(
        private readonly CardReportRepository $cardReports,
    ) {
    }

    #[OA\Get(
        path: '/admin/card-reports',
        summary: 'List reported cards with their reasons (open first; pass ?show_resolved=1 for all)',
        tags: ['Admin · Card Reports'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(
                name: 'show_resolved',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of card reports',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CardReport'))
            ),
        ]
    )]
    public function getCardReports(Request $request, Response $response): Response|ResponseInterface
    {
        $includeResolved = ($request->getQueryParams()['show_resolved'] ?? '0') === '1';

        return $response->withJson($this->cardReports->get($includeResolved));
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Patch(
        path: '/admin/card-reports/{report_id}',
        summary: 'Mark a card report resolved / reopened',
        tags: ['Admin · Card Reports'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'report_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [new OA\Property(property: 'resolved', type: 'boolean')])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean')])
            ),
            new OA\Response(response: 400, description: 'Invalid report ID'),
        ]
    )]
    public function updateCardReport(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reportId = (int)($args['report_id'] ?? 0);

        if ($reportId < 1) {
            return $response->withJson(['error' => 'Invalid report ID'], 400);
        }

        $params = $this->parsedBody($request);

        if (array_key_exists('resolved', $params)) {
            $this->cardReports->setResolved($reportId, (bool)$params['resolved']);
        }

        return $response->withJson(['success' => true]);
    }
}
