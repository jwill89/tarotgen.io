<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\UserRepository;
use Tarot\Service\PluginTokenService;
use Tarot\Utility\Session;

/**
 * The Dalamud plugin account-linking endpoints (OAuth-style, PKCE public client).
 * See plugin/docs/auth.md. TarotGen.io is its own identity provider: the browser
 * consent step ({@see authorize}) mints a short-lived code, and the plugin
 * exchanges it ({@see token}) for a long-lived Bearer token. Tokens are listed
 * and revoked from the account's "Connected Apps" screen ({@see listTokens},
 * {@see revokeToken}).
 */
class PluginAuthController extends AbstractController
{
    public function __construct(
        private readonly PluginTokenService $pluginTokens,
        private readonly UserRepository $users,
    ) {
    }

    #[OA\Post(
        path: '/plugin/authorize',
        summary: 'Approve a plugin link and mint a PKCE authorization code (browser, session-authed)',
        tags: ['Plugin'],
        security: [['sessionCookie' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code_challenge', 'redirect_uri'],
                properties: [
                    new OA\Property(property: 'code_challenge', type: 'string'),
                    new OA\Property(property: 'code_challenge_method', type: 'string', default: 'S256'),
                    new OA\Property(property: 'redirect_uri', type: 'string', description: 'Loopback URI, e.g. http://127.0.0.1:<port>/callback'),
                    new OA\Property(property: 'state', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Loopback redirect target carrying the authorization code',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'redirect_uri', type: 'string')])
            ),
            new OA\Response(response: 400, description: 'Invalid challenge or non-loopback redirect_uri'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function authorize(Request $request, Response $response): Response|ResponseInterface
    {
        Session::start();
        $userId = Session::userId();
        if ($userId === null) {
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        $body        = $this->parsedBody($request);
        $challenge   = trim((string)($body['code_challenge'] ?? ''));
        $method      = strtoupper(trim((string)($body['code_challenge_method'] ?? 'S256')));
        $redirectUri = trim((string)($body['redirect_uri'] ?? ''));
        $state       = (string)($body['state'] ?? '');

        if ($challenge === '' || $method !== 'S256') {
            return $response->withJson(['error' => 'A valid S256 code_challenge is required.'], 400);
        }

        // The code is handed back to a loopback listener the plugin controls;
        // refusing any non-loopback target stops a crafted link from exfiltrating it.
        if (!$this->isLoopbackUri($redirectUri)) {
            return $response->withJson(['error' => 'redirect_uri must be a loopback address.'], 400);
        }

        $code = $this->pluginTokens->createAuthorizationCode($userId, $challenge);

        $glue   = str_contains($redirectUri, '?') ? '&' : '?';
        $target = $redirectUri . $glue . http_build_query(['code' => $code, 'state' => $state]);

        return $response->withJson(['redirect_uri' => $target]);
    }

    #[OA\Post(
        path: '/plugin/token',
        summary: 'Exchange a PKCE authorization code for a plugin Bearer token',
        tags: ['Plugin'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'code_verifier'],
                properties: [
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'code_verifier', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The issued token (returned once)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'token_type', type: 'string'),
                    new OA\Property(property: 'scope', type: 'string'),
                    new OA\Property(property: 'display_name', type: 'string'),
                ])
            ),
            new OA\Response(response: 400, description: 'Invalid or expired authorization code'),
        ]
    )]
    public function token(Request $request, Response $response): Response|ResponseInterface
    {
        $body     = $this->parsedBody($request);
        $code     = (string)($body['code'] ?? '');
        $verifier = (string)($body['code_verifier'] ?? '');

        $token = $this->pluginTokens->exchangeCode($code, $verifier);
        if ($token === null) {
            return $response->withJson(['error' => 'Invalid or expired authorization code.'], 400);
        }

        // Resolve the just-issued token to its owner so the plugin can show
        // "Linked as ‹name›" without a separate identity endpoint.
        $displayName = '';
        $userId = $this->pluginTokens->resolveBearer('Bearer ' . $token);
        if ($userId !== null) {
            $user = $this->users->findById($userId);
            if ($user !== null) {
                $displayName = $user->display_name;
            }
        }

        return $response->withJson([
            'token'        => $token,
            'token_type'   => 'Bearer',
            'scope'        => 'account',
            'display_name' => $displayName,
        ]);
    }

    #[OA\Get(
        path: '/account/tokens',
        summary: "The signed-in user's linked plugin tokens (Connected Apps)",
        tags: ['Plugin'],
        security: [['sessionCookie' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array of linked tokens (never includes the token value)',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/PluginToken'))
            ),
        ]
    )]
    public function listTokens(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->pluginTokens->listForUser($this->authUserId($request)));
    }

    /**
     * @param array<string,string> $args
     */
    #[OA\Delete(
        path: '/account/tokens/{id}',
        summary: 'Revoke a linked plugin token (forces the plugin to re-link)',
        tags: ['Plugin'],
        security: [['sessionCookie' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Revoked'),
            new OA\Response(response: 404, description: 'Token not found'),
        ]
    )]
    public function revokeToken(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $tokenId = (int)($args['id'] ?? 0);

        if (!$this->pluginTokens->revoke($this->authUserId($request), $tokenId)) {
            return $response->withJson(['error' => 'Token not found.'], 404);
        }

        return $response->withStatus(204);
    }

    /** The authenticated user id, set by the AccountAuth/UserAuth middleware. */
    private function authUserId(Request $request): int
    {
        $id = $request->getAttribute('auth_user_id');
        return is_int($id) && $id > 0 ? $id : 0;
    }

    /** Whether a URL points at the local loopback interface (per RFC 8252). */
    private function isLoopbackUri(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host   = parse_url($url, PHP_URL_HOST);

        if ($scheme !== 'http' || !is_string($host)) {
            return false;
        }

        $host = strtolower($host);
        return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }
}
