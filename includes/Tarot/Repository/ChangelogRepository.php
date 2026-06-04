<?php

namespace Tarot\Repository;

use Tarot\Data\ChangelogData;
use Tarot\Structure\ChangelogEntry;

class ChangelogRepository
{
    private ChangelogData $data;

    public function __construct(ChangelogData $data)
    {
        $this->data = $data;
    }

    public function get(?int $entry_id = null): array|ChangelogEntry
    {
        $results = $this->data->retrieve($entry_id);

        if ($entry_id !== null && count($results) > 0) {
            return $results[0];
        }

        return $results;
    }

    public function create(array $data): ?ChangelogEntry
    {
        return $this->data->store($data);
    }

    public function update(int $entry_id, array $data): ?ChangelogEntry
    {
        return $this->data->update($entry_id, $data);
    }

    public function delete(int $entry_id): bool
    {
        return $this->data->delete($entry_id);
    }
}
