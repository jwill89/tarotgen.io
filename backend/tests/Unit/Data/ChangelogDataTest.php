<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\ChangelogData;
use Tarot\Repository\ChangelogRepository;
use Tarot\Structure\ChangelogEntry;
use Tarot\Utility\Clock;

/**
 * The changelog store, through the repository the controllers use, on the
 * production schema in memory: newest-first order, what an admin's input is
 * cleaned to, and partial updates.
 */
#[CoversClass(ChangelogData::class)]
#[CoversClass(ChangelogRepository::class)]
final class ChangelogDataTest extends TestCase
{
    private ChangelogRepository $changelog;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec((string)file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $this->changelog = new ChangelogRepository(new ChangelogData($db));
    }

    public function testEntriesAreListedNewestFirstAndTheLaterOneWinsATie(): void
    {
        $this->store('January', '2026-01-15');
        $this->store('March, first', '2026-03-01');
        $this->store('March, second', '2026-03-01');

        $this->assertSame(['March, second', 'March, first', 'January'], $this->titles());
    }

    public function testAnEntryIsFetchedByIdAndAnUnknownIdFindsNoEntry(): void
    {
        $id = $this->store('Passkeys', '2026-02-01')->entry_id;

        $entry = $this->changelog->get($id);
        $this->assertInstanceOf(ChangelogEntry::class, $entry);
        $this->assertSame('Passkeys', $entry->title);

        // The controller answers 404 for anything that isn't an entry.
        $this->assertSame([], $this->changelog->get($id + 1));
    }

    public function testStoringTrimsAndCapsTheTitleAndFallsBackToTodayForABadDate(): void
    {
        $entry = $this->changelog->create(['title' => '  ' . str_repeat('t', 250) . '  ', 'entry_date' => 'next tuesday']);

        $this->assertNotNull($entry);
        $this->assertSame(str_repeat('t', 200), $entry->title);
        $this->assertSame('', $entry->body);
        $this->assertSame(Clock::today(), $entry->entry_date);
    }

    public function testAnUpdateChangesOnlyTheFieldsItIsGiven(): void
    {
        $id = $this->store('Passkeys', '2026-02-01', 'Sign in with a passkey.')->entry_id;

        $updated = $this->changelog->update($id, ['body' => 'Sign in with a passkey or Google.']);

        $this->assertNotNull($updated);
        $this->assertSame('Passkeys', $updated->title);
        $this->assertSame('Sign in with a passkey or Google.', $updated->body);
        $this->assertSame('2026-02-01', $updated->entry_date);

        $redated = $this->changelog->update($id, ['title' => ' Passkeys and Google ', 'entry_date' => '2026-02-03']);
        $this->assertNotNull($redated);
        $this->assertSame('Passkeys and Google', $redated->title);
        $this->assertSame('2026-02-03', $redated->entry_date);
    }

    public function testAnUpdateWithNothingToChangeOrForAMissingEntryFindsNoEntry(): void
    {
        $id = $this->store('Passkeys', '2026-02-01')->entry_id;

        $this->assertNull($this->changelog->update($id, ['entry_id' => 99]));
        $this->assertNull($this->changelog->update($id + 1, ['title' => 'Ghost']));

        $this->assertSame(['Passkeys'], $this->titles());
    }

    public function testDeletingRemovesOnlyThatEntry(): void
    {
        $first = $this->store('First', '2026-01-01')->entry_id;
        $this->store('Second', '2026-01-02');

        $this->assertTrue($this->changelog->delete($first));

        $this->assertSame(['Second'], $this->titles());
    }

    private function store(string $title, string $date, string $body = ''): ChangelogEntry
    {
        $entry = $this->changelog->create(['title' => $title, 'entry_date' => $date, 'body' => $body]);
        $this->assertNotNull($entry);
        return $entry;
    }

    /** @return list<string> */
    private function titles(): array
    {
        $entries = $this->changelog->get();
        $this->assertIsArray($entries);
        return array_map(static fn(ChangelogEntry $entry): string => $entry->title, $entries);
    }
}
