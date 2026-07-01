<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * Properties use asymmetric visibility (PHP 8.4): reads are public
 * ($reading->reading_id), writes go through the setters below. PDO's FETCH_CLASS
 * and the array constructor still hydrate rows, while external code cannot mutate
 * the entity — only the Data/Service layer builds one up via the setters.
 */
#[OA\Schema(description: 'A saved tarot reading.')]
class Reading extends AbstractStructure
{
    #[OA\Property(type: 'string')]
    public private(set) string $reading_id = '';

    /** Serialized to a nested JSON object in the response (see jsonSerialize). */
    #[OA\Property(type: 'object')]
    public private(set) string $reading_info = '';

    #[OA\Property(type: 'string')]
    public private(set) string $reading_time = '';

    #[OA\Property(type: 'integer', nullable: true)]
    public private(set) ?int $user_id = null;

    #[OA\Property(type: 'boolean')]
    public private(set) bool $hide_user = false;

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $reading_name = null;

    #[OA\Property(type: 'string', nullable: true)]
    public private(set) ?string $reading_notes = null;

    /** One-way lock: when true the owner can no longer draw additional cards. */
    #[OA\Property(type: 'boolean')]
    public private(set) bool $is_final = false;

    /** Derived flag — true when a view password is set. The hash itself is never exposed. */
    #[OA\Property(type: 'boolean')]
    public private(set) bool $password_protected = false;

    public function setReadingId(string $reading_id): self
    {
        $this->reading_id = $reading_id;
        return $this;
    }

    public function setReadingInfo(string $reading_info): self
    {
        $this->reading_info = $reading_info;
        return $this;
    }

    public function setReadingTime(string $reading_time): self
    {
        $this->reading_time = $reading_time;
        return $this;
    }

    public function setUserId(?int $user_id): self
    {
        $this->user_id = $user_id;
        return $this;
    }

    public function setHideUser(bool $hide_user): self
    {
        $this->hide_user = $hide_user;
        return $this;
    }

    public function setReadingName(?string $reading_name): self
    {
        $this->reading_name = $reading_name;
        return $this;
    }

    public function setReadingNotes(?string $reading_notes): self
    {
        $this->reading_notes = $reading_notes;
        return $this;
    }

    public function setIsFinal(bool $is_final): self
    {
        $this->is_final = $is_final;
        return $this;
    }

    public function setPasswordProtected(bool $password_protected): self
    {
        $this->password_protected = $password_protected;
        return $this;
    }

    /**
     * Override serialization so reading_info is emitted as a decoded JSON object
     * rather than a double-encoded string.
     *
     * @return array<string,mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();

        // Decode the JSON string so it becomes a nested object in the response.
        $decoded = json_decode($this->reading_info, true, 512, JSON_THROW_ON_ERROR);
        $data['reading_info'] = is_array($decoded) ? $decoded : [];

        return $data;
    }
}
