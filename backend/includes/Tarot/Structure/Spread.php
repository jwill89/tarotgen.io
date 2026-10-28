<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A predefined tarot spread layout.')]
class Spread extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $spread_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $description = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $card_count = 1;

    /**
     * Array of position objects, each shaped:
     * ['order' => int, 'title' => string, 'x' => float, 'y' => float, 'rotation' => int]
     *
     * @var list<array<string,mixed>>
     */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition')))]
    public private(set) array $positions = [] {
        set(array|string $value) => self::jsonList($value);
    }
}
