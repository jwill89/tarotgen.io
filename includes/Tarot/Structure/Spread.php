<?php

namespace Tarot\Structure;

class Spread extends AbstractStructure
{
    protected int $spread_id = 0;
    protected string $name = '';
    protected string $description = '';
    protected int $card_count = 1;

    /**
     * Array of position objects, each shaped:
     * ['order' => int, 'title' => string, 'x' => float, 'y' => float, 'rotation' => int]
     */
    protected array $positions = [];

    public function getSpreadId(): int
    {
        return $this->spread_id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCardCount(): int
    {
        return $this->card_count;
    }

    public function getPositions(): array
    {
        return $this->positions;
    }
}
