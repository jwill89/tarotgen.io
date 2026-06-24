<?php

namespace Tarot\Structure;

class ChangelogEntry extends AbstractStructure
{
    protected int $entry_id = 0;
    protected string $title = '';
    protected string $body = '';
    protected string $entry_date = '';

    public function getEntryId(): int
    {
        return $this->entry_id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getEntryDate(): string
    {
        return $this->entry_date;
    }
}
