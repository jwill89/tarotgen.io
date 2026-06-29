<?php

namespace Tarot\Structure;

class Contact extends AbstractStructure
{
    protected int $contact_id = 0;
    protected ?int $user_id = null;
    protected string $name = '';
    protected string $email = '';
    protected string $message = '';
    protected int $is_read = 0;
    protected string $submitted_at = '';

    public function getContactId(): int
    {
        return $this->contact_id;
    }

    public function getUserId(): ?int
    {
        return $this->user_id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isRead(): bool
    {
        return (bool)$this->is_read;
    }

    public function getSubmittedAt(): string
    {
        return $this->submitted_at;
    }
}
