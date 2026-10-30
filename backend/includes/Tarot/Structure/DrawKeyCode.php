<?php

namespace Tarot\Structure;

/**
 * A draw-key code still waiting to be redeemed, as redeeming it needs it.
 * Internal: never sent to the client, so it has no API schema.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class DrawKeyCode extends AbstractStructure
{
    public private(set) int $id = 0;

    /** The PKCE S256 challenge the redeeming verifier must match. */
    public private(set) string $code_challenge = '';
}
