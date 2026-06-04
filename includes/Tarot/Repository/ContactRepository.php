<?php

namespace Tarot\Repository;

use Tarot\Data\ContactData;
use Tarot\Structure\Contact;

class ContactRepository
{
    private ContactData $data;

    public function __construct(ContactData $data)
    {
        $this->data = $data;
    }

    /**
     * @return Contact[]
     */
    public function get(?bool $unreadOnly = null): array
    {
        return $this->data->retrieve($unreadOnly);
    }

    public function create(array $data): ?Contact
    {
        return $this->data->store($data);
    }

    public function markRead(int $contact_id, bool $read = true): bool
    {
        return $this->data->markRead($contact_id, $read);
    }

    public function countUnread(): int
    {
        return $this->data->countUnread();
    }
}

