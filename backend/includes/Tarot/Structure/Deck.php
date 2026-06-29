<?php

namespace Tarot\Structure;

class Deck extends AbstractStructure
{
    /** Fallback standard card count when a deck's system is missing/orphaned. */
    public const int DEFAULT_TOTAL_CARDS = 78;

    protected int $deck_id = 0;
    protected int $deck_system_id = 1;
    protected string $name = '';
    protected string $artist = '';
    protected string $purchase_url = '';
    protected int $additional_cards = 0;
    protected float $card_aspect_w = 5.0;
    protected float $card_aspect_h = 8.6;
    protected bool $approved = true;
    protected bool $usable = true;
    protected ?int $submitted_by = null;

    /** Populated by JOIN queries — the deck system's short name. */
    protected string $system_short_name = '';

    /** Populated by JOIN queries — the deck system's total cards. */
    protected int $system_total_cards = 0;

    public function getDeckId(): int
    {
        return $this->deck_id;
    }

    public function getDeckSystemId(): int
    {
        return $this->deck_system_id;
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

    public function getAdditionalCards(): int
    {
        return $this->additional_cards;
    }

    public function getCardAspectW(): float
    {
        return $this->card_aspect_w;
    }

    public function getCardAspectH(): float
    {
        return $this->card_aspect_h;
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function isUsable(): bool
    {
        return $this->usable;
    }

    public function getSubmittedBy(): ?int
    {
        return $this->submitted_by;
    }

    public function getSystemShortName(): string
    {
        return $this->system_short_name;
    }

    public function getSystemTotalCards(): int
    {
        return $this->system_total_cards;
    }

    /**
     * The authoritative standard card count for this deck: its deck system's
     * total, falling back to a standard 78-card tarot deck when the system is
     * missing (e.g. an orphaned deck whose system was deleted).
     */
    public function getEffectiveTotalCards(): int
    {
        return $this->system_total_cards > 0 ? $this->system_total_cards : self::DEFAULT_TOTAL_CARDS;
    }
}
