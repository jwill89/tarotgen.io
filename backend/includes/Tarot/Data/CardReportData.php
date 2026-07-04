<?php

namespace Tarot\Data;

use Tarot\Structure\CardReport;

/**
 * Storage for card scan-error reports. Reporting a card upserts one row per
 * (deck, card): the first report creates it, subsequent reports bump the counter
 * (and reopen it if an admin had marked it resolved).
 */
class CardReportData extends AbstractData
{
    /** Record a report for a card, creating the row or incrementing its counter. */
    public function report(int $deckId, int $cardId, string $cardName): void
    {
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO card_reports
                    (deck_id, card_id, card_name, report_count, first_reported_at, last_reported_at)
                VALUES (:deck, :card, :name, 1, :now, :now)
                ON CONFLICT(deck_id, card_id) DO UPDATE SET
                    report_count     = report_count + 1,
                    card_name        = CASE WHEN excluded.card_name <> \'\'
                                            THEN excluded.card_name ELSE card_name END,
                    last_reported_at = excluded.last_reported_at,
                    resolved_at      = NULL';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':deck' => $deckId, ':card' => $cardId, ':name' => $cardName, ':now' => $now]);
    }

    /**
     * All reports (most-reported first), with the deck name resolved. Open reports
     * come before resolved ones unless $includeResolved filters resolved out.
     *
     * @return list<CardReport>
     */
    public function retrieve(bool $includeResolved): array
    {
        $sql = 'SELECT r.report_id, r.deck_id, COALESCE(d.name, \'\') AS deck_name,
                       r.card_id, r.card_name, r.report_count, r.resolved_at,
                       r.first_reported_at, r.last_reported_at
                FROM card_reports r
                LEFT JOIN "decks" d ON r.deck_id = d.deck_id';

        if (!$includeResolved) {
            $sql .= ' WHERE r.resolved_at IS NULL';
        }

        $sql .= ' ORDER BY (r.resolved_at IS NOT NULL), r.report_count DESC, r.last_reported_at DESC';

        return $this->fetchAllAs($sql, [], CardReport::class);
    }

    /** Mark a report handled (resolved) or reopen it. True when a row changed. */
    public function setResolved(int $reportId, bool $resolved): bool
    {
        $stmt = $this->db->prepare('UPDATE card_reports SET resolved_at = :val WHERE report_id = :id');
        $stmt->execute([':val' => $resolved ? date('Y-m-d H:i:s') : null, ':id' => $reportId]);

        return $stmt->rowCount() > 0;
    }
}
