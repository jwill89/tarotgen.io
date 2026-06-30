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
     * The request body as a normalized associative array.
     *
     * Slim's `getParsedBody()` is typed `array|object|null` (it can yield a
     * decoded JSON object or `null` for an empty/unparseable body). Controllers
     * only ever read JSON request bodies as `field => value` maps, so this funnels
     * every body through one place that guarantees an `array<string,mixed>` —
     * removing the repeated `?? []` dance and giving the static analyzer a real
     * offset-accessible type at every call site.
     *
     * @return array<string,mixed>
     */
    protected function parsedBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

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
