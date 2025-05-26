<?php

namespace Tarot\Repository;

use Tarot\Data\CardData;
use Tarot\Structure\Card;

class CardRepository
{
    private CardData $data;

    public function __construct()
    {
        if (!isset($this->data)) {
            $this->data = new CardData();
        }
    }

    public function get(int $card_id): ?Card
    {
        return $this->data->retrieve($card_id);
    }

    public function getMultiple(array $card_ids): array
    {
        return $this->data->retrieveMultiple($card_ids);
    }
}
