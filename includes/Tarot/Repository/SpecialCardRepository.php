<?php

namespace Tarot\Repository;

use Tarot\Data\SpecialCardData;
use Tarot\Structure\SpecialCard;

class SpecialCardRepository
{
    private SpecialCardData $data;

    public function __construct()
    {
        $this->data = new SpecialCardData();
    }

    public function get(int $deck_id, int $card_id): ?SpecialCard
    {
        return $this->data->retrieve($deck_id, $card_id);
    }

    public function getMultiple(int $deck_id, array $card_ids): array
    {
        return $this->data->retrieveMultiple($deck_id, $card_ids);
    }
}
