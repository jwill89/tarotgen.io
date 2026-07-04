<?php

namespace Routes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use Tarot\Service\ShareService;

/**
 * Gate a relay route on a valid plugin **client token** (the routing credential
 * every install holds, guest or account-linked). The resolved routing id is
 * stashed as the `client_id` request attribute for the controller to read.
 *
 * This is deliberately separate from {@see AccountAuth}: a client token routes
 * shares and carries presence but grants no account access, so a leaked client
 * token can never reach account data.
 */
readonly class ClientAuth
{
    public function __construct(private ShareService $shares)
    {
    }

    public function __invoke(Request $request, Handler $handler): ResponseInterface
    {
        $clientId = $this->shares->resolveClient($request->getHeaderLine('Authorization'));

        if ($clientId !== null) {
            return $handler->handle($request->withAttribute('client_id', $clientId));
        }

        $response = new Response();
        $response->getBody()->write(json_encode(['error' => 'Invalid or missing client token'], JSON_THROW_ON_ERROR));

        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }
}
