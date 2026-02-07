<?php

namespace Tarot\Structure;

class Card extends AbstractStructure
{
    protected int $card_id = 0;
    protected string $name = '';
    protected ?string $meaning = '';
    protected ?string $advice = '';
    protected ?string $meaning_reversed = '';
    protected ?string $advice_reversed = '';
    protected string $name_thoth = '';
    protected ?string $meaning_thoth = '';
    protected ?string $advice_thoth = '';
    protected ?string $meaning_thoth_reversed = '';
    protected ?string $advice_thoth_reversed = '';

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

    public function getMeaningReversed(): ?string
    {
        return $this->meaning_reversed;
    }

    public function getAdviceReversed(): ?string
    {
        return $this->advice_reversed;
    }

    public function getNameThoth(): string
    {
        return $this->name_thoth;
    }

    public function getMeaningThoth(): ?string
    {
        return $this->meaning_thoth;
    }

    public function getAdviceThoth(): ?string
    {
        return $this->advice_thoth;
    }

    public function getMeaningThothReversed(): ?string
    {
        return $this->meaning_thoth_reversed;
    }

    public function getAdviceThothReversed(): ?string
    {
        return $this->advice_thoth_reversed;
    }
}
