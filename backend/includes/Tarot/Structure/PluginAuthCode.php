<?php

namespace Tarot\Structure;

/**
 * A live authorization code, as redeeming it for a plugin token needs it.
 * Internal: never sent to the client, so it has no API schema.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class PluginAuthCode extends AbstractStructure
{
    public private(set) int $id = 0;

    public private(set) int $user_id = 0;

    /** The PKCE S256 challenge the redeeming verifier must match. */
    public private(set) string $code_challenge = '';

    public private(set) string $scope = 'account';
}
