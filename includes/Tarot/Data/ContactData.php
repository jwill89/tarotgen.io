<?php

namespace Tarot\Data;

use PDO;
use Tarot\Structure\Contact;

class ContactData extends AbstractData
{
    /**
     * Retrieve contacts, optionally filtered by read status.
     *
     * When a contact has a user_id, the display_name and email are resolved from
     * the users table so the admin always sees up-to-date account info.
     *
     * @return Contact[]
     */
    public function retrieve(?bool $unreadOnly = null): array
    {
        $query = "SELECT c.contact_id, c.user_id, 
                         COALESCE(u.display_name, c.name) AS name,
                         COALESCE(u.email, c.email) AS email,
                         c.message, c.is_read, c.submitted_at
                  FROM contacts c
                  LEFT JOIN users u ON c.user_id = u.user_id";

        if ($unreadOnly === true) {
            $query .= " WHERE c.is_read = 0";
        }

        $query .= " ORDER BY c.submitted_at DESC";

        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Store a new contact submission.
     */
    /**
     * @param array<string,mixed> $data
     */
    public function store(array $data): ?Contact
    {
        $sql = "INSERT INTO contacts (user_id, name, email, message)
                VALUES (:user_id, :name, :email, :message)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $data['user_id'] ?? null,
            ':name'    => mb_substr(trim((string)($data['name'] ?? '')), 0, 200),
            ':email'   => mb_substr(trim((string)($data['email'] ?? '')), 0, 320),
            ':message' => trim((string)($data['message'] ?? '')),
        ]);

        $id = (int)$this->db->lastInsertId();
        $results = $this->retrieveById($id);
        return $results;
    }

    /**
     * Mark a contact as read (or unread).
     */
    public function markRead(int $contact_id, bool $read = true): bool
    {
        $stmt = $this->db->prepare("UPDATE contacts SET is_read = :is_read WHERE contact_id = :contact_id");
        return $stmt->execute([
            ':is_read'    => $read ? 1 : 0,
            ':contact_id' => $contact_id,
        ]);
    }

    /**
     * Count unread contacts.
     */
    public function countUnread(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM contacts WHERE is_read = 0")->fetchColumn();
    }

    private function retrieveById(int $contact_id): ?Contact
    {
        $query = "SELECT c.contact_id, c.user_id,
                         COALESCE(u.display_name, c.name) AS name,
                         COALESCE(u.email, c.email) AS email,
                         c.message, c.is_read, c.submitted_at
                  FROM contacts c
                  LEFT JOIN users u ON c.user_id = u.user_id
                  WHERE c.contact_id = :contact_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':contact_id' => $contact_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): Contact
    {
        return new Contact([
            'contact_id'   => (int)$row['contact_id'],
            'user_id'      => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'name'         => (string)$row['name'],
            'email'        => (string)$row['email'],
            'message'      => (string)$row['message'],
            'is_read'      => (int)$row['is_read'],
            'submitted_at' => (string)$row['submitted_at'],
        ]);
    }
}

