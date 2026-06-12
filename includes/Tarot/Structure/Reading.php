<?php

namespace Tarot\Structure;

class Reading extends AbstractStructure
{
    protected string $reading_id = '';
    protected string $reading_info = '';
    protected string $reading_time = '';
    protected ?int $user_id = null;
    protected bool $hide_user = false;
    protected ?string $reading_name = null;
    protected ?string $reading_notes = null;
    /** One-way lock: when true the owner can no longer draw additional cards. */
    protected bool $is_final = false;
    /** Derived flag — true when a view password is set. The hash itself is never exposed. */
    protected bool $password_protected = false;

    public function getReadingId(): string
    {
        return $this->reading_id;
    }

    public function setReadingId(string $reading_id): void
    {
        $this->reading_id = $reading_id;
    }

    public function getReadingInfo(): string
    {
        return $this->reading_info;
    }

    public function setReadingInfo(string $reading_info): void
    {
        $this->reading_info = $reading_info;
    }

    public function getReadingTime(): string
    {
        return $this->reading_time;
    }

    public function setReadingTime(string $reading_time): void
    {
        $this->reading_time = $reading_time;
    }

    public function getUserId(): ?int
    {
        return $this->user_id;
    }

    public function setUserId(?int $user_id): void
    {
        $this->user_id = $user_id;
    }

    public function isHideUser(): bool
    {
        return $this->hide_user;
    }

    public function setHideUser(bool $hide_user): void
    {
        $this->hide_user = $hide_user;
    }

    public function getReadingName(): ?string
    {
        return $this->reading_name;
    }

    public function setReadingName(?string $reading_name): void
    {
        $this->reading_name = $reading_name;
    }

    public function getReadingNotes(): ?string
    {
        return $this->reading_notes;
    }

    public function setReadingNotes(?string $reading_notes): void
    {
        $this->reading_notes = $reading_notes;
    }

    public function isFinal(): bool
    {
        return $this->is_final;
    }

    public function setIsFinal(bool $is_final): void
    {
        $this->is_final = $is_final;
    }

    public function isPasswordProtected(): bool
    {
        return $this->password_protected;
    }

    public function setPasswordProtected(bool $password_protected): void
    {
        $this->password_protected = $password_protected;
    }

    /**
     * Override serialization so reading_info is emitted as a decoded JSON object
     * rather than a double-encoded string.
     */
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();

        // Decode the JSON string so it becomes a nested object in the response.
        $decoded = json_decode($this->reading_info, true, 512, JSON_THROW_ON_ERROR);
        $data['reading_info'] = is_array($decoded) ? $decoded : [];

        return $data;
    }
}
