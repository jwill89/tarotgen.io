<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A user-created custom spread.')]
class UserSpread extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $user_spread_id = 0;

    #[OA\Property(type: 'integer')]
    public private(set) int $user_id = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $name = '';

    #[OA\Property(type: 'string')]
    public private(set) string $description = '';

    #[OA\Property(type: 'integer')]
    public private(set) int $card_count = 1;

    /** @var list<array<string,mixed>> */
    #[OA\Property(type: 'array', items: new OA\Items(type: 'object'))]
    public private(set) array $positions = [];

    #[OA\Property(type: 'string')]
    public private(set) string $created_at = '';

    #[OA\Property(type: 'string')]
    public private(set) string $updated_at = '';
}
