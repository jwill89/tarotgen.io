<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Config\Env;
use Tarot\Repository\UserRepository;
use Tarot\Service\AuthService;
use OpenApi\Spec as OAS;

/**
 * User accounts: activation, the admin flag, resending activation, deletion.
 */
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthService $auth,
    ) {
    }

    #[OAS\Operation\Get(
        path: '/admin/users',
        summary: 'List all user accounts',
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of users',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/User'))
            ),
        ]
    )]
    public function getUsers(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->users->getAll());
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/users/{user_id}/activate',
        summary: 'Activate a user account',
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Activated',
                content: new OAS\MediaType\Json(
                    properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))]
                )
            ),
            new OAS\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function activateUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        if ($user_id < 1 || $this->users->findById($user_id) === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $this->users->activate($user_id);

        return $response->withJson(['success' => true]);
    }

    /**
     * Partial update of an account. Currently the only editable field is the
     * `is_admin` flag (previously the dedicated POST …/admin action).
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Patch(
        path: '/admin/users/{user_id}',
        summary: 'Partially update a user account',
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'is_admin', schema: new OAS\Schema(type: 'boolean')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated user',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/User')
            ),
            new OAS\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function updateUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        if ($user_id < 1 || $this->users->findById($user_id) === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $params = $this->parsedBody($request);

        if (array_key_exists('is_admin', $params)) {
            $this->users->setAdmin($user_id, (bool)$params['is_admin']);
        }

        // Return the refreshed user so the client can update the row in place.
        return $response->withJson($this->users->findById($user_id));
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/users/{user_id}/resend-activation',
        summary: 'Resend a user activation email',
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Activation resent',
                content: new OAS\MediaType\Json(type: 'object')
            ),
            new OAS\Response(response: 400, description: 'Could not resend activation'),
        ]
    )]
    public function resendActivation(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        $result = $this->auth->resendActivation($user_id);

        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not resend activation.'], 400);
        }

        $payload = ['success' => true, 'emailed' => $result['emailed'] ?? false];

        // Outside production, surface the link when email isn't configured.
        if (!Env::isProduction() && !($result['emailed'] ?? false)) {
            $payload['activation_link'] = $result['activation_link'] ?? null;
        }

        return $response->withJson($payload);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/users/{user_id}',
        summary: 'Delete a user account',
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function deleteUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int)($args['user_id'] ?? 0);

        if ($user_id < 1 || $this->users->findById($user_id) === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $this->users->delete($user_id);

        return $response->withStatus(204);
    }
}
