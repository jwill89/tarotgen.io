<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\ContactRepository;

/**
 * Messages sent through the public contact form.
 */
class AdminContactController extends AbstractController
{
    public function __construct(
        private readonly ContactRepository $contacts,
    ) {
    }

    #[OA\Get(
        path: '/admin/contacts',
        summary: 'List submitted contact messages',
        tags: ['Admin · Contacts'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(
                name: 'show_read',
                in: 'query',
                required: false,
                description: 'Set to "1" to include already-read messages',
                schema: new OA\Schema(type: 'string', enum: ['0', '1'])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of contacts',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Contact'))
            ),
        ]
    )]
    public function getContacts(Request $request, Response $response): Response|ResponseInterface
    {
        $showRead = ($request->getQueryParams()['show_read'] ?? '0') === '1';
        $unreadOnly = $showRead ? null : true;

        return $response->withJson($this->contacts->get($unreadOnly));
    }

    /**
     * Partial update of a contact message. Currently the only editable field is
     * the `is_read` flag (previously the dedicated POST …/read action).
     *
     * @param array<string,string> $args
     */
    #[OA\Patch(
        path: '/admin/contacts/{contact_id}',
        summary: 'Partially update a contact message',
        tags: ['Admin · Contacts'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'contact_id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'is_read', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'success', type: 'boolean')]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid contact ID'),
        ]
    )]
    public function updateContact(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $contact_id = (int)($args['contact_id'] ?? 0);

        if ($contact_id < 1) {
            return $response->withJson(['error' => 'Invalid contact ID'], 400);
        }

        $params = $this->parsedBody($request);

        if (array_key_exists('is_read', $params)) {
            $this->contacts->markRead($contact_id, (bool)$params['is_read']);
        }

        return $response->withJson(['success' => true]);
    }
}
