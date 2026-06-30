<?php

namespace Tarot\Structure;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
class Contact extends AbstractStructure
{
    public private(set) int $contact_id = 0;
    public private(set) ?int $user_id = null;
    public private(set) string $name = '';
    public private(set) string $email = '';
    public private(set) string $message = '';
    public private(set) int $is_read = 0;
    public private(set) string $submitted_at = '';

    /** Whether the message has been read (the stored column is a 0/1 int). */
    public function isRead(): bool
    {
        return (bool)$this->is_read;
    }
}
