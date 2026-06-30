<?php

namespace Tarot\Structure;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class UserSpread extends AbstractStructure
{
    public private(set) int $user_spread_id = 0;
    public private(set) int $user_id = 0;
    public private(set) string $name = '';
    public private(set) string $description = '';
    public private(set) int $card_count = 1;
    /** @var list<array<string,mixed>> */
    public private(set) array $positions = [];
    public private(set) string $created_at = '';
    public private(set) string $updated_at = '';
}
