<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * A spread the user has favourited: an official spread, or one of their own.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A favourited spread: which table it lives in, and its id there.')]
class FavoriteSpread extends AbstractStructure
{
    #[OA\Property(type: 'string', enum: ['public', 'personal'], description: '`public` for an official spread, `personal` for a custom one.')]
    public private(set) string $spread_type = 'public';

    #[OA\Property(type: 'integer', description: 'The spread_id or user_spread_id, according to spread_type.')]
    public private(set) int $spread_id = 0;
}
