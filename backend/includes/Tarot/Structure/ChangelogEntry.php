<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A changelog entry.')]
class ChangelogEntry extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $entry_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $title = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $body = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $entry_date = '';
}
