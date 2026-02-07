<?php

namespace Tarot\Structure;

class Deck extends AbstractStructure
{
    protected int $deck_id = 0;
    protected string $name = '';
    protected string $artist = '';
    protected string $purchase_url = '';
    protected bool $is_thoth = false;
    protected bool $has_extras = false;
    protected bool $non_standard = false;
    protected int $total_cards = 78;
    protected int $additional_cards = 0;

    public function getDeckId(): int
    {
        return $this->deck_id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getArtist(): string
    {
        return $this->artist;
    }

    public function getPurchaseUrl(): string
    {
        return $this->purchase_url;
    }

    public function isThoth(): bool
    {
        return $this->is_thoth;
    }
    public function hasExtras(): bool
    {
        return $this->has_extras;
    }

    public function isNonStandard(): bool
    {
        return $this->non_standard;
    }

    public function getTotalCards(): int
    {
        return $this->total_cards;
    }

    public function getAdditionalCards(): int
    {
        return $this->additional_cards;
    }
}
