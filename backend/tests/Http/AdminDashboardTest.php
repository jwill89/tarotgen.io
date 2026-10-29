<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminDashboardController;
use Tarot\Data\ChangelogData;
use Tarot\Data\ContactData;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\SpecialCardData;
use Tarot\Data\SpreadData;
use Tarot\Service\StatsService;
use Tarot\Utility\Clock;

/**
 * The dashboard's numbers, read through /admin/summary (one request for the
 * tiles and the charts) and /admin/stats, against a known set of rows.
 */
#[CoversClass(AdminDashboardController::class)]
#[CoversClass(StatsService::class)]
final class AdminDashboardTest extends HttpTestCase
{
    public function testTheSummaryCountsEachKindOfThingAndSumsUpTheReadings(): void
    {
        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'approved' => true]);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => 'Thoth', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->service(SpecialCardData::class)->store(['deck_id' => $deck->deck_id, 'card_id' => 79, 'name' => 'Extra']);
        $this->service(SpreadData::class)->store(['name' => 'Three Card']);
        $this->service(ChangelogData::class)->store(['title' => 'Launch', 'body' => 'Hello']);
        $contacts = $this->service(ContactData::class);
        $contacts->store(['name' => 'A', 'email' => 'a@example.test', 'message' => 'Unread']);
        $read = $contacts->store(['name' => 'B', 'email' => 'b@example.test', 'message' => 'Read']);
        $this->assertNotNull($read);
        $contacts->markRead($read->contact_id);
        $this->createUser('reader@example.test');

        $threeCard = ['spread_id' => 4, 'name' => 'Three Card'];
        $recent = time() - 60;
        $this->reading('recent0001', $recent, $deck->deck_id, $threeCard);
        $this->reading('recent0002', $recent, $deck->deck_id, $threeCard);
        $this->reading('custom0001', time() - 3 * 86400, $deck->deck_id, ['spread_id' => 0, 'name' => 'My layout']);
        // A free draw (no spread at all) with a deck deleted since.
        $this->reading('free000001', time() - 10 * 86400, 999, null);
        $this->reading('old0000001', time() - 40 * 86400, 999, ['spread_id' => 9, 'name' => 'Celtic Cross']);

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
        $summary = $this->assertJsonResponse(200, $this->get('/admin/summary'));

        $this->assertDocumented('get', '/admin/summary', 200, $summary);
        $this->assertSame([
            'deckSystems' => 1, 'decks' => 1, 'specialCards' => 1, 'spreads' => 1,
            'readings' => 5, 'changelog' => 1, 'users' => 2, 'unreadContacts' => 1,
        ], $summary['counts']);
        $this->assertSame([
            'totals' => ['readings' => 5, 'last7' => 3, 'last30' => 4],
            'byType' => ['freeDraw' => 1, 'spread' => 3, 'custom' => 1],
            'topDecks' => [
                ['deck_id' => $deck->deck_id, 'name' => 'Thoth', 'count' => 3],
                ['deck_id' => 999, 'name' => 'Deck #999', 'count' => 2],
            ],
            'topSpreads' => [['name' => 'Three Card', 'count' => 2], ['name' => 'Celtic Cross', 'count' => 1]],
            'daily' => [
                ['date' => gmdate('Y-m-d', time() - 10 * 86400), 'count' => 1],
                ['date' => gmdate('Y-m-d', time() - 3 * 86400), 'count' => 1],
                ['date' => gmdate('Y-m-d', $recent), 'count' => 2],
            ],
        ], $summary['stats']);

        // The stats route is the same overview on its own.
        $stats = $this->assertJsonResponse(200, $this->get('/admin/stats'));
        $this->assertDocumented('get', '/admin/stats', 200, $stats);
        $this->assertSame($summary['stats'], $stats);
    }

    public function testAnEmptySiteReportsZeroesAndEmptyCharts(): void
    {
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $stats = $this->assertJsonResponse(200, $this->get('/admin/stats'));

        $this->assertDocumented('get', '/admin/stats', 200, $stats);
        $this->assertSame([
            'totals' => ['readings' => 0, 'last7' => 0, 'last30' => 0],
            'byType' => ['freeDraw' => 0, 'spread' => 0, 'custom' => 0],
            'topDecks' => [],
            'topSpreads' => [],
            'daily' => [],
        ], $stats);
    }

    public function testTheDashboardAndItsSessionCheckNeedAnAdmin(): void
    {
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $this->assertSame(['error' => 'Unauthorized'], $this->assertJsonResponse(401, $this->get('/admin/summary')));
        $this->assertSame(['error' => 'Unauthorized'], $this->assertJsonResponse(401, $this->get('/admin/stats')));
        $this->assertSame(['error' => 'Unauthorized'], $this->assertJsonResponse(401, $this->get('/admin/auth-check')));

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
        $checked = $this->assertJsonResponse(200, $this->get('/admin/auth-check'));
        $this->assertDocumented('get', '/admin/auth-check', 200, $checked);
        $this->assertSame(['authenticated' => true], $checked);
    }

    /** @param array{spread_id:int,name:string}|null $spread */
    private function reading(string $id, int $time, int $deckId, ?array $spread): void
    {
        $info = ['deck_id' => $deckId] + ($spread === null ? [] : ['spread' => $spread]);
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, reading_time) VALUES (?, ?, ?)')
            ->execute([$id, json_encode($info, JSON_THROW_ON_ERROR), gmdate(Clock::FORMAT, $time)]);
    }
}
