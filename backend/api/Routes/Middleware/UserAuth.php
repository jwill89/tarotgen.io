<?php

namespace Routes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use Tarot\Repository\UserRepository;
use Tarot\Utility\Session;

/**
 * Gate account-management routes on a logged-in, active user account. (Admin
 * privilege is not required here — these are a user's own self-service actions.)
 */
readonly class UserAuth
{
    public function __construct(private UserRepository $users)
    {
    }

    public function __invoke(Request $request, Handler $handler): ResponseInterface
    {
        Session::start();

        $userId = Session::userId();

        if ($userId !== null) {
            $user = $this->users->findById($userId);
            if ($user !== null && $user->is_active) {
                return $handler->handle($request);
            }
        }

        $response = new Response();
        $response->getBody()->write(json_encode(['error' => 'Not authenticated'], JSON_THROW_ON_ERROR));
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }
}
