<?php

namespace Routes\Internal;

use Psr\Http\Message\ServerRequestInterface;
use Tarot\Utility\Session;

/**
 * Base for HTTP controllers. Dependencies are now injected directly into each
 * concrete controller's constructor (see the container definitions), so this
 * base only carries shared request/session helpers.
 */
abstract class AbstractController
{
    /**
     * Extract the client IP address from the request.
     */
    protected function clientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        return (string)($server['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * Start a session with "remember me" persistence support.
     */
    protected function startSessionWithPersistence(): void
    {
        Session::start();
    }

    /**
     * Mark the current session for 30-day persistence ("remember me").
     */
    protected function persistSession(): void
    {
        Session::persist();
    }
}
