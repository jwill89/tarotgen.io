<?php

namespace Tarot\Structure;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class ChangelogEntry extends AbstractStructure
{
    public private(set) int $entry_id = 0;
    public private(set) string $title = '';
    public private(set) string $body = '';
    public private(set) string $entry_date = '';
}
