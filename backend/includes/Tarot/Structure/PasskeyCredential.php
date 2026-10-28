<?php

namespace Tarot\Structure;

/**
 * What a passkey sign-in verifies an assertion against. Internal: never sent to
 * the client, so it has no API schema; the account page lists Passkey instead.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class PasskeyCredential extends AbstractStructure
{
    public private(set) int $passkey_id = 0;

    public private(set) int $user_id = 0;

    public private(set) string $credential_id = '';

    public private(set) string $public_key_pem = '';

    public private(set) int $sign_count = 0;
}
