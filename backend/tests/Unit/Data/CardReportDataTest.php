<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\CardReportData;
use Tarot\Repository\CardReportRepository;

/**
 * Exercises the card scan-error report store against an in-memory SQLite DB:
 * the report-or-increment upsert, deck-name resolution, and resolve/reopen.
 */
#[CoversClass(CardReportData::class)]
#[CoversClass(CardReportRepository::class)]
final class CardReportDataTest extends TestCase
{
    private CardReportRepository $reports;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE "decks" (deck_id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO \"decks\" (deck_id, name) VALUES (1, 'Rider-Waite'), (2, 'Thoth')");

        $pdo->exec(
            "CREATE TABLE card_reports (
                report_id         INTEGER PRIMARY KEY AUTOINCREMENT,
                deck_id           INTEGER NOT NULL,
                card_id           INTEGER NOT NULL,
                card_name         TEXT    NOT NULL DEFAULT '',
                report_count      INTEGER NOT NULL DEFAULT 1,
                resolved_at       TEXT    DEFAULT NULL,
                first_reported_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_reported_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $pdo->exec('CREATE UNIQUE INDEX idx_card_reports_card ON card_reports (deck_id, card_id)');

        $this->reports = new CardReportRepository(new CardReportData($pdo));
    }

    public function testFirstReportCreatesRowWithCountOneAndDeckName(): void
    {
        $this->reports->report(1, 0, 'The Fool');

        $rows = $this->reports->get(false);
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->deck_id);
        $this->assertSame('Rider-Waite', $rows[0]->deck_name);
        $this->assertSame(0, $rows[0]->card_id);
        $this->assertSame('The Fool', $rows[0]->card_name);
        $this->assertSame(1, $rows[0]->report_count);
        $this->assertNull($rows[0]->resolved_at);
    }

    public function testReReportingTheSameCardIncrementsTheCounter(): void
    {
        $this->reports->report(1, 5, 'The Hierophant');
        $this->reports->report(1, 5, 'The Hierophant');
        $this->reports->report(1, 5, 'The Hierophant');

        $rows = $this->reports->get(false);
        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]->report_count);
    }

    public function testDifferentCardsAndDecksAreSeparateRows(): void
    {
        $this->reports->report(1, 5, 'A');
        $this->reports->report(1, 6, 'B');
        $this->reports->report(2, 5, 'C');

        $this->assertCount(3, $this->reports->get(false));
    }

    public function testEmptyCardNameDoesNotOverwriteAnExistingName(): void
    {
        $this->reports->report(1, 5, 'The Hierophant');
        $this->reports->report(1, 5, '');

        $rows = $this->reports->get(false);
        $this->assertSame('The Hierophant', $rows[0]->card_name);
    }

    public function testResolveHidesFromOpenListButReopensOnNewReport(): void
    {
        $this->reports->report(1, 5, 'The Hierophant');
        $id = $this->reports->get(false)[0]->report_id;

        $this->assertTrue($this->reports->setResolved($id, true));
        $this->assertCount(0, $this->reports->get(false));           // hidden from open list
        $this->assertCount(1, $this->reports->get(true));            // still visible with all

        // A fresh report reopens it and bumps the count.
        $this->reports->report(1, 5, 'The Hierophant');
        $open = $this->reports->get(false);
        $this->assertCount(1, $open);
        $this->assertNull($open[0]->resolved_at);
        $this->assertSame(2, $open[0]->report_count);
    }

    public function testOpenReportsSortBeforeResolvedOnes(): void
    {
        $this->reports->report(1, 5, 'A');
        $this->reports->report(1, 6, 'B');

        // Resolve either one; the open one must still sort first in the full list.
        $this->reports->setResolved($this->reports->get(false)[0]->report_id, true);

        $all = $this->reports->get(true);
        $this->assertCount(2, $all);
        $this->assertNull($all[0]->resolved_at);        // open first
        $this->assertNotNull($all[1]->resolved_at);
    }
}
