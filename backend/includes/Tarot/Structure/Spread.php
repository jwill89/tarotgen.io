<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A predefined tarot spread layout.')]
class Spread extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $spread_id = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $name = '';

    #[OA\Property(type: 'string')]
    public private(set) string $description = '';

    #[OA\Property(type: 'integer')]
    public private(set) int $card_count = 1;

    /**
     * Array of position objects, each shaped:
     * ['order' => int, 'title' => string, 'x' => float, 'y' => float, 'rotation' => int]
     *
     * @var list<array<string,mixed>>
     */
    #[OA\Property(type: 'array', items: new OA\Items(type: 'object'))]
    public private(set) array $positions = [];
}
