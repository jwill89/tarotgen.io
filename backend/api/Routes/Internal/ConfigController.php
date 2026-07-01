<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Service\TurnstileService;

/**
 * Public, non-secret configuration the SPA needs at runtime. Only values that
 * are safe to expose to any visitor belong here (e.g. the Turnstile *site* key,
 * which is meant to be embedded in the page — never the secret).
 */
class ConfigController extends AbstractController
{
    public function __construct(
        private readonly TurnstileService $turnstile,
    ) {
    }

    #[OA\Get(
        path: '/config',
        summary: 'Public runtime configuration',
        tags: ['Config'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Non-secret config the SPA needs before rendering',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'turnstile_sitekey', type: 'string', nullable: true),
                    ]
                )
            ),
        ]
    )]
    public function get(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson([
            // Null when Turnstile isn't configured; the frontend then skips the widget.
            'turnstile_sitekey' => $this->turnstile->getSiteKey(),
        ]);
    }
}
