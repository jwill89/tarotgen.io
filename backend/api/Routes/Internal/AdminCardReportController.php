<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\CardReportRepository;
use OpenApi\Spec as OAS;

/**
 * Card image problems reported from the lightbox, and their resolution.
 */
class AdminCardReportController extends AbstractController
{
    public function __construct(
        private readonly CardReportRepository $cardReports,
    ) {
    }

    #[OAS\Operation\Get(
        path: '/admin/card-reports',
        summary: 'List reported cards with their reasons (open first; pass ?show_resolved=1 for all)',
        tags: ['Admin · Card Reports'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(
                name: 'show_resolved',
                in: 'query',
                required: false,
                schema: new OAS\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of card reports',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/CardReport'))
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
    #[OAS\Operation\Patch(
        path: '/admin/card-reports/{report_id}',
        summary: 'Mark a card report resolved / reopened',
        tags: ['Admin · Card Reports'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'report_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(properties: [new OAS\Property(property: 'resolved', schema: new OAS\Schema(type: 'boolean'))])
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated',
                content: new OAS\MediaType\Json(properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
            new OAS\Response(response: 400, description: 'Invalid report ID'),
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
