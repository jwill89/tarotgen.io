<?php

namespace Tarot\Structure;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class Spread extends AbstractStructure
{
    public private(set) int $spread_id = 0;
    public private(set) string $name = '';
    public private(set) string $description = '';
    public private(set) int $card_count = 1;

    /**
     * Array of position objects, each shaped:
     * ['order' => int, 'title' => string, 'x' => float, 'y' => float, 'rotation' => int]
     *
     * @var list<array<string,mixed>>
     */
    public private(set) array $positions = [];
}
