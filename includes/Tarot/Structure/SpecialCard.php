<?php

namespace Tarot\Structure;

class SpecialCard extends AbstractStructure
{
    protected int $deck_id = 0;
    protected int $card_id = 0;
    protected string $name = '';

    public function getDeckId(): int
    {
        return $this->deck_id;
    }

    public function getCardId(): int
    {
        return $this->card_id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
