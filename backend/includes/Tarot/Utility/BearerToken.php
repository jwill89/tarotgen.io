<?php

namespace Tarot\Utility;

/**
 * The credential in an `Authorization: Bearer …` header. The plugin sends one of
 * two there: a linked account's token (`tg_pat_…`) or, unlinked, its draw key
 * (`tg_dk_…`); each is looked up only by its own service, so neither can stand
 * in for the other.
 */
final class BearerToken
{
    /** The raw credential, or null when the header is absent or not a Bearer one. */
    public static function from(string $authorizationHeader): ?string
    {
        return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorizationHeader, $matches) === 1 ? $matches[1] : null;
    }
}
