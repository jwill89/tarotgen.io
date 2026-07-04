<?php

namespace Tarot\Data;

use Tarot\Structure\PluginMessage;

/**
 * Storage for the share inbox: durable, short-TTL messages routed to a recipient
 * client. Persisting the row (rather than holding a socket) is what lets a plain
 * short-poll deliver a "push" and also gives a brief offline mailbox. Expired
 * rows are swept lazily so the table stays small.
 */
class PluginMessageData extends AbstractData
{
    /** Queue one message for a recipient. Returns the new message id. */
    public function enqueue(
        int $recipientClientId,
        int $senderClientId,
        string $senderLabel,
        ?string $senderCharacter,
        ?string $senderWorld,
        string $type,
        string $payload,
        string $expiresAt
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_messages
                (recipient_client_id, sender_client_id, sender_label, sender_character, sender_world,
                 type, payload, created_at, expires_at)
             VALUES (:rid, :sid, :label, :schar, :sworld, :type, :payload, :now, :expires)'
        );
        $stmt->execute([
            ':rid'     => $recipientClientId,
            ':sid'     => $senderClientId,
            ':label'   => $senderLabel,
            ':schar'   => $senderCharacter,
            ':sworld'  => $senderWorld,
            ':type'    => $type,
            ':payload' => $payload,
            ':now'     => date('Y-m-d H:i:s'),
            ':expires' => $expiresAt,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Drain the undelivered, unexpired messages for a recipient: fetch them, then
     * stamp them delivered in one transaction so a concurrent poll can't
     * double-deliver.
     *
     * @return list<PluginMessage>
     */
    public function drain(int $recipientClientId): array
    {
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $messages = $this->fetchAllAs(
                'SELECT id, sender_label, sender_client_id, sender_character, sender_world, type, payload, created_at
                 FROM plugin_messages
                 WHERE recipient_client_id = :rid AND delivered_at IS NULL AND expires_at > :now
                 ORDER BY id ASC',
                [':rid' => $recipientClientId, ':now' => $now],
                PluginMessage::class
            );

            if ($messages !== []) {
                $stmt = $this->db->prepare(
                    'UPDATE plugin_messages SET delivered_at = :now
                     WHERE recipient_client_id = :rid AND delivered_at IS NULL AND expires_at > :now'
                );
                $stmt->execute([':now' => $now, ':rid' => $recipientClientId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $messages;
    }

    /** How many messages a sender has queued since a cutoff (distinct-recipient abuse guard). */
    public function distinctRecipientsSince(int $senderClientId, string $since): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT recipient_client_id) FROM plugin_messages
             WHERE sender_client_id = :sid AND created_at > :since'
        );
        $stmt->execute([':sid' => $senderClientId, ':since' => $since]);

        return (int)$stmt->fetchColumn();
    }

    /** Delete messages past their TTL (delivered or not). Cheap housekeeping. */
    public function sweepExpired(): void
    {
        $stmt = $this->db->prepare('DELETE FROM plugin_messages WHERE expires_at <= :now');
        $stmt->execute([':now' => date('Y-m-d H:i:s')]);
    }
}
