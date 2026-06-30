<?php

namespace Tarot\Structure;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class SpecialCard extends AbstractStructure
{
    public private(set) int $deck_id = 0;
    public private(set) int $card_id = 0;
    public private(set) string $name = '';
    public private(set) ?string $keywords = '';
    public private(set) ?string $meaning = '';
    public private(set) ?string $advice = '';
    public private(set) ?string $keywords_reversed = '';
    public private(set) ?string $meaning_reversed = '';
    public private(set) ?string $advice_reversed = '';
}
