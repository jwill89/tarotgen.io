<?php

namespace Tarot\Repository;

use Tarot\Data\ReadingData;
use Tarot\Structure\Reading;

class ReadingRepository
{
    private ReadingData $data;

    public function __construct()
    {
        $this->data = new ReadingData();
    }

    public function get(string $reading_id): ?Reading
    {
        return $this->data->retrieve($reading_id);
    }

    public function save(Reading $reading): ?Reading
    {
        return $this->data->store($reading);
    }
}
