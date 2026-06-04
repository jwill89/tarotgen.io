<?php

namespace Tarot\Data;

use Tarot\Structure\SpecialCard;

class SpecialCardData extends AbstractData
{
    public function retrieve(int $deck_id, int $card_id): ?SpecialCard
    {
        return $this->fetchOne(
            "SELECT * FROM special_cards WHERE deck_id = :deck_id AND card_id = :card_id",
            [':deck_id' => $deck_id, ':card_id' => $card_id],
            SpecialCard::class
        );
    }

    public function retrieveMultiple(int $deck_id, array $card_ids): array
    {
        if (empty($card_ids)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($card_ids), '?'));

        return $this->fetchAllAs(
            "SELECT * FROM special_cards WHERE deck_id = ? AND card_id IN ($placeholders)",
            array_merge([$deck_id], array_values($card_ids)),
            SpecialCard::class
        );
    }

    public function retrieveAll(?int $deck_id = null): array
    {
        if ($deck_id !== null) {
            return $this->fetchAllAs(
                "SELECT * FROM special_cards WHERE deck_id = :deck_id ORDER BY card_id",
                [':deck_id' => $deck_id],
                SpecialCard::class
            );
        }

        return $this->fetchAllAs(
            "SELECT * FROM special_cards ORDER BY deck_id, card_id",
            [],
            SpecialCard::class
        );
    }

    public function store(array $data): ?SpecialCard
    {
        $deck_id = (int)($data['deck_id'] ?? 0);
        $card_id = (int)($data['card_id'] ?? 0);

        $stmt = $this->db->prepare(
            "INSERT INTO special_cards
                (deck_id, card_id, name, keywords, meaning, advice, keywords_reversed, meaning_reversed, advice_reversed)
             VALUES
                (:deck_id, :card_id, :name, :keywords, :meaning, :advice, :keywords_reversed, :meaning_reversed, :advice_reversed)"
        );
        $stmt->execute([
            ':deck_id'           => $deck_id,
            ':card_id'           => $card_id,
            ':name'              => $data['name'] ?? '',
            ':keywords'          => $data['keywords'] ?? '',
            ':meaning'           => $data['meaning'] ?? '',
            ':advice'            => $data['advice'] ?? '',
            ':keywords_reversed' => $data['keywords_reversed'] ?? '',
            ':meaning_reversed'  => $data['meaning_reversed'] ?? '',
            ':advice_reversed'   => $data['advice_reversed'] ?? '',
        ]);

        return $this->retrieve($deck_id, $card_id);
    }

    public function update(int $deck_id, int $card_id, array $data): ?SpecialCard
    {
        $allowed = [
            'name', 'keywords', 'meaning', 'advice',
            'keywords_reversed', 'meaning_reversed', 'advice_reversed',
        ];

        if (!$this->applyUpdate('special_cards', $data, $allowed, ['deck_id' => $deck_id, 'card_id' => $card_id])) {
            return null;
        }

        return $this->retrieve($deck_id, $card_id);
    }

    public function delete(int $deck_id, int $card_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM special_cards WHERE deck_id = :deck_id AND card_id = :card_id");

        return $stmt->execute([':deck_id' => $deck_id, ':card_id' => $card_id]);
    }
}
