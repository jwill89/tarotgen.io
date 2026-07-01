<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OA\Schema(description: 'A contact-form submission.')]
class Contact extends AbstractStructure
{
    #[OA\Property(type: 'integer')]
    public private(set) int $contact_id = 0;

    #[OA\Property(type: 'integer', nullable: true)]
    public private(set) ?int $user_id = null;

    #[OA\Property(type: 'string')]
    public private(set) string $name = '';

    #[OA\Property(type: 'string')]
    public private(set) string $email = '';

    #[OA\Property(type: 'string')]
    public private(set) string $message = '';

    #[OA\Property(type: 'integer')]
    public private(set) int $is_read = 0;

    #[OA\Property(type: 'string')]
    public private(set) string $submitted_at = '';

    /** Whether the message has been read (the stored column is a 0/1 int). */
    public function isRead(): bool
    {
        return (bool)$this->is_read;
    }
}
