<?php

namespace Tarot\Repository;

use Tarot\Data\SpecialCardData;
use Tarot\Structure\SpecialCard;

class SpecialCardRepository
{
    private SpecialCardData $data;

    public function __construct()
    {
        if (!isset($this->data)) {
            $this->data = new SpecialCardData();
        }
    }

    public function get(int $deck_id, int $card_id): ?SpecialCard
    {
        return $this->data->retrieve($deck_id, $card_id);
    }
}
