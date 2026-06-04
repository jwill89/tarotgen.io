<?php

namespace Tarot\Structure;

class PendingSpread extends AbstractStructure
{
    protected int $pending_id = 0;
    protected string $name = '';
    protected string $description = '';
    protected int $card_count = 1;

    /**
     * Array of position objects, each shaped:
     * ['order' => int, 'title' => string, 'x' => float, 'y' => float, 'rotation' => int]
     */
    protected array $positions = [];

    /** Resolved submitter label: the linked account's display name, or "Guest". */
    protected string $submitter = '';
    protected ?int $user_id = null;
    protected string $submitted_at = '';

    public function getPendingId(): int
    {
        return $this->pending_id;
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

    public function getSubmitter(): string
    {
        return $this->submitter;
    }

    public function getUserId(): ?int
    {
        return $this->user_id;
    }

    public function getSubmittedAt(): string
    {
        return $this->submitted_at;
    }
}
