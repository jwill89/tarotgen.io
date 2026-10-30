<?php

namespace Tarot\Utility;

/**
 * PKCE (RFC 7636) for the plugin's two browser flows, the account link and the
 * draw key: the plugin keeps a random verifier, the browser step stores only its
 * S256 challenge, and a code is redeemed only with the verifier that made it. A
 * code caught on its way to the loopback listener is useless without it.
 */
final class Pkce
{
    /** The only method accepted: `plain` would send the verifier itself through the browser. */
    public const string METHOD = 'S256';

    /** The S256 challenge for a verifier: base64url(SHA-256(verifier)), unpadded. */
    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** Whether $verifier is the one $challenge was made from (compared in constant time). */
    public static function verifies(string $verifier, string $challenge): bool
    {
        return hash_equals($challenge, self::challengeFor($verifier));
    }
}
