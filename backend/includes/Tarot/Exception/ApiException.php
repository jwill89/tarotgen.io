<?php

namespace Tarot\Exception;

use RuntimeException;
use Throwable;

/**
 * A domain-level failure that maps cleanly onto an HTTP response.
 *
 * Service-layer code throws this to signal a client-facing error (bad input,
 * missing resource, etc.) without knowing anything about HTTP. Controllers
 * catch it and render `{ "error": <message> }` with the carried status code,
 * keeping request/response concerns out of the services.
 */
class ApiException extends RuntimeException
{
    private readonly int $statusCode;

    public function __construct(string $message, int $statusCode = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
