<?php

namespace Tarot\Structure;

class DeckSystemCard extends AbstractStructure
{
    protected int $deck_system_id = 0;
    protected int $card_id = 0;
    protected string $name = '';
    protected ?string $keywords = null;
    protected ?string $meaning = null;
    protected ?string $advice = null;
    protected ?string $reversed_keywords = null;
    protected ?string $reversed_meaning = null;
    protected ?string $reversed_advice = null;

    public function getDeckSystemId(): int
    {
        return $this->deck_system_id;
    }

    public function getCardId(): int
    {
        return $this->card_id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKeywords(): ?string
    {
        return $this->keywords;
    }

    public function getMeaning(): ?string
    {
        return $this->meaning;
    }

    public function getAdvice(): ?string
    {
        return $this->advice;
    }

    public function getReversedKeywords(): ?string
    {
        return $this->reversed_keywords;
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
