<?php

namespace Tarot\Structure;

class SpecialCard extends AbstractStructure
{
    protected int $deck_id = 0;
    protected int $card_id = 0;
    protected string $name = '';
    protected ?string $keywords = '';
    protected ?string $meaning = '';
    protected ?string $advice = '';
    protected ?string $keywords_reversed = '';
    protected ?string $meaning_reversed = '';
    protected ?string $advice_reversed = '';

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

    public function getKeywordsReversed(): ?string
    {
        return $this->keywords_reversed;
    }

    public function getMeaningReversed(): ?string
    {
        return $this->meaning_reversed;
    }

    public function getAdviceReversed(): ?string
    {
        return $this->advice_reversed;
    }
}
