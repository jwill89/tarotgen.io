<?php

namespace Tarot\Structure;

class Reading extends AbstractStructure
{
    protected string $reading_id = '';
    protected string $reading_info = '';
    protected string $reading_time = '';

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
}
