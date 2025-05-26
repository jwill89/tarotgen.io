<?php

namespace Tarot\Structure;

class Card extends AbstractStructure
{
    protected int $card_id = 0;
    protected string $name = '';
    protected ?string $meaning = '';
    protected ?string $advice = '';
    protected ?string $reversed_meaning = '';
    protected ?string $reversed_advice = '';

    public function getCardId(): int
    {
        return $this->card_id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMeaning(): ?string
    {
        return $this->meaning;
    }

    public function getAdvice(): ?string
    {
        return $this->advice;
    }

    public function getReversedMeaning(): ?string
    {
        return $this->reversed_meaning;
    }

    public function getReversedAdvice(): ?string
    {
        return $this->reversed_advice;
    }
}
