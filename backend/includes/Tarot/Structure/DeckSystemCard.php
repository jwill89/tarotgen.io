<?php

namespace Tarot\Structure;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class DeckSystemCard extends AbstractStructure
{
    public private(set) int $deck_system_id = 0;
    public private(set) int $card_id = 0;
    public private(set) string $name = '';
    public private(set) ?string $keywords = null;
    public private(set) ?string $meaning = null;
    public private(set) ?string $advice = null;
    public private(set) ?string $reversed_keywords = null;
    public private(set) ?string $reversed_meaning = null;
    public private(set) ?string $reversed_advice = null;
}
