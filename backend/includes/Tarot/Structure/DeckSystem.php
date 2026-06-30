<?php

namespace Tarot\Structure;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class DeckSystem extends AbstractStructure
{
    public private(set) int $deck_system_id = 0;
    public private(set) string $name = '';
    public private(set) string $short_name = '';
    public private(set) int $total_cards = 78;
    public private(set) bool $approved = true;
    public private(set) ?int $submitted_by = null;
}
