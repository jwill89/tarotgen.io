<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A changelog entry.')]
class ChangelogEntry extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $entry_id = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $title = '';

    #[OA\Property(type: 'string')]
    public private(set) string $body = '';

    #[OA\Property(type: 'string')]
    public private(set) string $entry_date = '';
}
