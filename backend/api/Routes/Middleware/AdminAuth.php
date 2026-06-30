<?php

namespace Routes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use Tarot\Repository\UserRepository;
use Tarot\Utility\Session;

/**
 * Gate admin routes on a logged-in user account that is active and flagged
 * `is_admin`. The flag is re-checked from the database on every request, so
 * revoking admin (or deleting/deactivating the account) takes effect
 * immediately rather than lingering for the life of a session.
 */
readonly class AdminAuth
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
            if ($user !== null && $user->is_active && $user->is_admin) {
                return $handler->handle($request);
            }
        }

        $response = new Response();
        $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }
}
