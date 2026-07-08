<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Service\ShareService;
use Tarot\Utility\RateLimiter;

/**
 * The chatless-share relay endpoints (client-token authed via {@see \Routes\Middleware\ClientAuth}).
 * A recipient publishes its identity + consent ({@see register}) and drains queued
 * shares ({@see inbox}); a sender pushes a reading to a self-published
 * `Character@World` ({@see share}). Harassment controls: server-enforced `nobody`
 * tier + block list ({@see block}), per-sender/per-IP throttles, and — since the
 * server can't see game state — party/friends filtering happens in the recipient's
 * plugin before the popup shows. See plugin/docs/sharing.md.
 */
class PluginShareController extends AbstractController
{
    private const int RATE_WINDOW_SECONDS = 60;
    private const int SHARE_MAX_PER_WINDOW_CLIENT = 20;
    private const int SHARE_MAX_PER_WINDOW_IP = 30;

    public function __construct(private readonly ShareService $shares)
    {
    }

    #[OA\Post(
        path: '/plugin/clients/register',
        summary: 'Publish this install\'s recipient identity + consent tier (and refresh presence)',
        tags: ['Plugin'],
        security: [['clientToken' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(
                    property: 'accept_tier',
                    type: 'string',
                    enum: ['nobody', 'party', 'friends', 'party_or_friends', 'anyone']
                ),
                new OA\Property(
                    property: 'characters',
                    type: 'array',
                    description: 'Full desired identity set (synced): each {character_name, world}. [] unpublishes all.',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'character_name', type: 'string'),
                        new OA\Property(property: 'world', type: 'string'),
                    ])
                ),
                new OA\Property(property: 'character_name', type: 'string', nullable: true, description: 'Legacy single-identity form'),
                new OA\Property(property: 'world', type: 'string', nullable: true, description: 'Legacy single-identity form'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The updated client view',
                content: new OA\JsonContent(ref: '#/components/schemas/PluginClient')
            ),
            new OA\Response(response: 401, description: 'Invalid or missing client token'),
        ]
    )]
    public function register(Request $request, Response $response): Response|ResponseInterface
    {
        $clientId = $this->clientId($request);
        if ($clientId === 0) {
            return $response->withJson(['error' => 'Invalid or missing client token'], 401);
        }

        $body       = $this->parsedBody($request);
        $identities = $this->identitiesFromBody($body);

        $client = $this->shares->register(
            $clientId,
            isset($body['accept_tier']) ? (string)$body['accept_tier'] : null,
            $identities,
        );

        return $response->withJson($client);
    }

    /**
     * Normalise the register body into a desired identity set, or null to leave it
     * untouched. Prefers the `characters` array; falls back to the legacy single
     * `character_name`/`world` pair.
     *
     * @param array<string,mixed> $body
     * @return list<array{character_name:string,world:string}>|null
     */
    private function identitiesFromBody(array $body): ?array
    {
        if (isset($body['characters']) && is_array($body['characters'])) {
            $out = [];
            foreach ($body['characters'] as $c) {
                if (is_array($c)) {
                    $out[] = [
                        'character_name' => (string)($c['character_name'] ?? ''),
                        'world'          => (string)($c['world'] ?? ''),
                    ];
                }
            }
            return $out;
        }

        $name  = isset($body['character_name']) ? trim((string)$body['character_name']) : '';
        $world = isset($body['world']) ? trim((string)$body['world']) : '';
        if ($name !== '' && $world !== '') {
            return [['character_name' => $name, 'world' => $world]];
        }

        return null;
    }

    #[OA\Get(
        path: '/plugin/inbox',
        summary: 'Drain queued shares for this install (delivers each once)',
        tags: ['Plugin'],
        security: [['clientToken' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Queued shares (may be empty)',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/PluginMessage'))
            ),
            new OA\Response(response: 401, description: 'Invalid or missing client token'),
        ]
    )]
    public function inbox(Request $request, Response $response): Response|ResponseInterface
    {
        $clientId = $this->clientId($request);
        if ($clientId === 0) {
            return $response->withJson(['error' => 'Invalid or missing client token'], 401);
        }

        return $response
            ->withJson($this->shares->drainInbox($clientId))
            ->withHeader('Cache-Control', 'no-store');
    }

    #[OA\Post(
        path: '/plugin/share',
        summary: 'Push a reading share to a self-published Character@World',
        tags: ['Plugin'],
        security: [['clientToken' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['character_name', 'world', 'reading_id'],
                properties: [
                    new OA\Property(property: 'character_name', type: 'string', description: "Recipient's character"),
                    new OA\Property(property: 'world', type: 'string', description: "Recipient's home world"),
                    new OA\Property(property: 'reading_id', type: 'string', description: 'The share code to deliver'),
                    new OA\Property(property: 'sender_label', type: 'string', description: 'Display name shown to the recipient'),
                    new OA\Property(property: 'sender_character', type: 'string', description: "Sender's own character"),
                    new OA\Property(property: 'sender_world', type: 'string', description: "Sender's own home world"),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Accepted (delivered only if the recipient is reachable and accepting)'
            ),
            new OA\Response(response: 400, description: 'Malformed request (missing recipient or reading)'),
            new OA\Response(response: 429, description: 'Sending too quickly'),
        ]
    )]
    public function share(Request $request, Response $response): Response|ResponseInterface
    {
        $clientId = $this->clientId($request);
        if ($clientId === 0) {
            return $response->withJson(['error' => 'Invalid or missing client token'], 401);
        }

        $ip            = $this->clientIp($request);
        $clientLimiter = new RateLimiter('plugin_share_client', self::SHARE_MAX_PER_WINDOW_CLIENT, self::RATE_WINDOW_SECONDS);
        $ipLimiter     = new RateLimiter('plugin_share_ip', self::SHARE_MAX_PER_WINDOW_IP, self::RATE_WINDOW_SECONDS);
        if ($clientLimiter->isLimited((string)$clientId) || $ipLimiter->isLimited($ip)) {
            return $response->withJson(['error' => 'You are sharing too quickly. Please slow down.'], 429);
        }

        // Count every well-formed attempt (not just delivered ones): the 429 signal
        // must depend on the sender's own volume alone, never on whether a recipient
        // exists — otherwise it would re-open the presence oracle the uniform 'sent'
        // response closes.
        $clientLimiter->hit((string)$clientId);
        $ipLimiter->hit($ip);

        $body   = $this->parsedBody($request);
        $status = $this->shares->send(
            $clientId,
            isset($body['sender_label']) ? (string)$body['sender_label'] : '',
            isset($body['sender_character']) ? (string)$body['sender_character'] : '',
            isset($body['sender_world']) ? (string)$body['sender_world'] : '',
            isset($body['character_name']) ? (string)$body['character_name'] : '',
            isset($body['world']) ? (string)$body['world'] : '',
            isset($body['reading_id']) ? (string)$body['reading_id'] : '',
        );

        return $status === 'invalid'
            ? $response->withJson(['error' => 'A recipient character and reading are required.'], 400)
            : $response->withJson(['status' => 'sent']);
    }

    #[OA\Post(
        path: '/plugin/clients/block',
        summary: 'Block or unblock a sender by their routing id',
        tags: ['Plugin'],
        security: [['clientToken' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['action', 'client_id'],
                properties: [
                    new OA\Property(property: 'action', type: 'string', enum: ['block', 'unblock']),
                    new OA\Property(property: 'client_id', type: 'integer', description: 'The sender routing id to (un)block'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Applied'),
            new OA\Response(response: 400, description: 'Unknown action'),
            new OA\Response(response: 401, description: 'Invalid or missing client token'),
        ]
    )]
    public function block(Request $request, Response $response): Response|ResponseInterface
    {
        $clientId = $this->clientId($request);
        if ($clientId === 0) {
            return $response->withJson(['error' => 'Invalid or missing client token'], 401);
        }

        $body    = $this->parsedBody($request);
        $action  = isset($body['action']) ? (string)$body['action'] : '';
        $blocked = isset($body['client_id']) ? (int)$body['client_id'] : 0;

        switch ($action) {
            case 'block':
                $this->shares->block($clientId, $blocked);
                break;
            case 'unblock':
                $this->shares->unblock($clientId, $blocked);
                break;
            default:
                return $response->withJson(['error' => 'action must be "block" or "unblock".'], 400);
        }

        return $response->withStatus(204);
    }

    /** The client routing id, set by the ClientAuth middleware. */
    private function clientId(Request $request): int
    {
        $id = $request->getAttribute('client_id');
        return is_int($id) && $id > 0 ? $id : 0;
    }
}
