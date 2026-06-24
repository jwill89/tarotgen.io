<?php

namespace Tarot\Structure;

class UserSpread extends AbstractStructure
{
    protected int $user_spread_id = 0;
    protected int $user_id = 0;
    protected string $name = '';
    protected string $description = '';
    protected int $card_count = 1;
    /** @var list<array<string,mixed>> */
    protected array $positions = [];
    protected string $created_at = '';
    protected string $updated_at = '';

    public function getUserSpreadId(): int
    {
        return $this->user_spread_id;
    }

    public function getUserId(): int
    {
        return $this->user_id;
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

    /**
     * @return list<array<string,mixed>>
     */
    public function getPositions(): array
    {
        return $this->positions;
    }

    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): string
    {
        return $this->updated_at;
    }
}

