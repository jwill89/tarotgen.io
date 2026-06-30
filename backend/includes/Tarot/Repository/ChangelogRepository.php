<?php

namespace Tarot\Repository;

use Tarot\Data\ChangelogData;
use Tarot\Structure\ChangelogEntry;

readonly class ChangelogRepository
{
    public function __construct(private ChangelogData $data)
    {
    }

    /**
     * @return list<ChangelogEntry>|ChangelogEntry
     */
    public function get(?int $entry_id = null): array|ChangelogEntry
    {
        $results = $this->data->retrieve($entry_id);

        if ($entry_id !== null && count($results) > 0) {
            return $results[0];
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): ?ChangelogEntry
    {
        return $this->data->store($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $entry_id, array $data): ?ChangelogEntry
    {
        return $this->data->update($entry_id, $data);
    }

    public function delete(int $entry_id): bool
    {
        return $this->data->delete($entry_id);
    }
}
