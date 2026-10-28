<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * One of a user's passkeys as their account page lists it. The public key and
 * signature counter aren't here: only signing in needs them (PasskeyCredential).
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: "A passkey registered to the signed-in user's account.")]
class Passkey extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $passkey_id = 0;

    #[OA\Property(type: 'string', description: 'The WebAuthn credential id, base64url-encoded.')]
    public private(set) string $credential_id = '';

    #[OA\Property(type: 'string')]
    public private(set) string $name = '';

    #[OA\Property(type: 'string')]
    public private(set) string $created_at = '';

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $last_used_at = null;
}
