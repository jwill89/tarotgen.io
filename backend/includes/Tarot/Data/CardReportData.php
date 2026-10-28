<?php

namespace Tarot\Data;

use Tarot\Structure\CardReport;
use Tarot\Structure\CardReportSubmission;
use Throwable;
use Tarot\Utility\Clock;

/**
 * Storage for card reports. Reporting a card upserts one row per (deck, card):
 * the first report creates it, subsequent reports bump the counter (and reopen
 * it if an admin had marked it resolved). The reason each reporter gave is kept
 * as a separate submission row under it.
 */
class CardReportData extends AbstractData
{
    /**
     * Record a report for a card, creating the row or incrementing its counter,
     * and store the reporter's reason when there is one. A null $reason (older
     * plugin builds don't send one) only bumps the counter.
     */
    public function report(int $deckId, int $cardId, string $cardName, ?string $reason, string $details): void
    {
        $now = Clock::now();

        $this->db->beginTransaction();

        try {
            // A report on a card an admin already resolved is a new problem, so it
            // starts a new round. SET expressions all read the pre-update row, so
            // `round` sees the old resolved_at even though it is cleared alongside.
            $this->db->prepare(
                'INSERT INTO card_reports
                    (deck_id, card_id, card_name, report_count, first_reported_at, last_reported_at)
                 VALUES (:deck, :card, :name, 1, :now, :now)
                 ON CONFLICT(deck_id, card_id) DO UPDATE SET
                    report_count     = report_count + 1,
                    card_name        = CASE WHEN excluded.card_name <> \'\'
                                            THEN excluded.card_name ELSE card_name END,
                    last_reported_at = excluded.last_reported_at,
                    round            = round + (resolved_at IS NOT NULL),
                    resolved_at      = NULL'
            )->execute([':deck' => $deckId, ':card' => $cardId, ':name' => $cardName, ':now' => $now]);

            if ($reason !== null) {
                $this->db->prepare(
                    'INSERT INTO card_report_submissions (report_id, round, reason, details, submitted_at)
                     SELECT report_id, round, :reason, :details, :now
                     FROM card_reports
                     WHERE deck_id = :deck AND card_id = :card'
                )->execute([
                    ':reason'  => $reason,
                    ':details' => $details,
                    ':now'     => $now,
                    ':deck'    => $deckId,
                    ':card'    => $cardId,
                ]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /**
     * All reports (most-reported first) with the deck name resolved and each
     * one's submissions attached. Open reports come before resolved ones unless
     * $includeResolved filters resolved out.
     *
     * @return list<CardReport>
     */
    public function retrieve(bool $includeResolved): array
    {
        $sql = 'SELECT r.report_id, r.deck_id, COALESCE(d.name, \'\') AS deck_name,
                       r.card_id, r.card_name, r.report_count, r.resolved_at,
                       r.first_reported_at, r.last_reported_at, r.round
                FROM card_reports r
                LEFT JOIN "decks" d ON r.deck_id = d.deck_id';

        if (!$includeResolved) {
            $sql .= ' WHERE r.resolved_at IS NULL';
        }

        $sql .= ' ORDER BY (r.resolved_at IS NOT NULL), r.report_count DESC, r.last_reported_at DESC';

        $reports = $this->fetchAllAs($sql, [], CardReport::class);

        if ($reports === []) {
            return [];
        }

        $byReport = [];
        foreach ($this->submissionsFor(array_map(static fn(CardReport $r): int => $r->report_id, $reports)) as $s) {
            $byReport[$s->report_id][] = $s;
        }

        foreach ($reports as $report) {
            $report->setProperties(['submissions' => $byReport[$report->report_id] ?? []]);
        }

        return $reports;
    }

    /** Mark a report handled (resolved) or reopen it. True when a row changed. */
    public function setResolved(int $reportId, bool $resolved): bool
    {
        $stmt = $this->db->prepare('UPDATE card_reports SET resolved_at = :val WHERE report_id = :id');
        $stmt->execute([':val' => $resolved ? Clock::now() : null, ':id' => $reportId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The submissions for the given reports, newest first.
     *
     * @param list<int> $reportIds
     * @return list<CardReportSubmission>
     */
    private function submissionsFor(array $reportIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($reportIds), '?'));

        return $this->fetchAllAs(
            "SELECT submission_id, report_id, round, reason, details, submitted_at
             FROM card_report_submissions
             WHERE report_id IN ({$placeholders})
             ORDER BY submitted_at DESC, submission_id DESC",
            $reportIds,
            CardReportSubmission::class
        );
    }
}
