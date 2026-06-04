<?php

namespace Tarot\Structure;

class DeckSystem extends AbstractStructure
{
    protected int $deck_system_id = 0;
    protected string $name = '';
    protected string $short_name = '';
    protected int $total_cards = 78;
    protected bool $approved = true;
    protected ?int $submitted_by = null;

    public function getDeckSystemId(): int
    {
        return $this->deck_system_id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getShortName(): string
    {
        return $this->short_name;
    }

    public function getTotalCards(): int
    {
        return $this->total_cards;
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function getSubmittedBy(): ?int
    {
        return $this->submitted_by;
    }
}

