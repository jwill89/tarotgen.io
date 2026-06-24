<?php

namespace Tarot\Repository;

use Tarot\Data\ReadingData;
use Tarot\Structure\Reading;

class ReadingRepository
{
    private ReadingData $data;

    public function __construct(ReadingData $data)
    {
        $this->data = $data;
    }

    public function get(string $reading_id): ?Reading
    {
        return $this->data->retrieve($reading_id);
    }

    /** @return Reading[] */
    public function listByUser(int $userId): array
    {
        return $this->data->listByUser($userId);
    }

    public function save(Reading $reading, ?string $passwordHash = null): ?Reading
    {
        return $this->data->store($reading, $passwordHash);
    }

    public function verifyPassword(string $reading_id, string $password): bool
    {
        return $this->data->verifyPassword($reading_id, $password);
    }

    /**
     * @param array<string,mixed> $fields
     */
    public function updateMeta(string $reading_id, int $userId, array $fields): ?Reading
    {
        return $this->data->updateMeta($reading_id, $userId, $fields);
    }

    public function updateReadingInfo(string $reading_id, string $readingInfo, ?int $userId = null): ?Reading
    {
        return $this->data->updateReadingInfo($reading_id, $readingInfo, $userId);
    }

    /** Mark a reading as final (one-way lock), scoped to its owner. */
    public function markFinal(string $reading_id, int $userId): ?Reading
    {
        return $this->data->markFinal($reading_id, $userId);
    }

    public function deleteOwned(string $reading_id, int $userId): bool
    {
        return $this->data->deleteForOwner($reading_id, $userId);
    }

    /** Admin: delete any reading. */
    public function delete(string $reading_id): bool
    {
        return $this->data->delete($reading_id);
    }

    /**
     * Admin: paginated list of all readings.
     *
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function listAll(int $limit = 50, int $offset = 0): array
    {
        return $this->data->listAll($limit, $offset);
    }

    /**
     * Admin: delete guest readings older than $days days.
     *
     * @return int Number of rows deleted.
     */
    public function cleanGuest(int $days): int
    {
        return $this->data->cleanGuestOlderThan($days);
    }
}
