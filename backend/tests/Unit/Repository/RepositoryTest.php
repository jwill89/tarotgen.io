<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\ContactData;
use Tarot\Data\DeckData;
use Tarot\Data\PendingSpreadData;
use Tarot\Repository\ContactRepository;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Structure\Contact;
use Tarot\Structure\Deck;
use Tarot\Structure\PendingSpread;
use Tarot\Structure\Spread;

#[CoversClass(ContactRepository::class)]
#[CoversClass(DeckRepository::class)]
#[CoversClass(PendingSpreadRepository::class)]
final class RepositoryTest extends TestCase
{
    // ── get(): the shared single-or-list pattern ────────────────

    public function testGetWithIdReturnsTheSingleMatch(): void
    {
        $deck = new Deck(['deck_id' => 5]);

        $data = $this->createStub(DeckData::class);
        $data->method('retrieve')->willReturn([$deck]);

        $this->assertSame($deck, (new DeckRepository($data))->get(5));
    }

    public function testGetWithoutIdReturnsTheFullList(): void
    {
        $list = [new Deck(['deck_id' => 1]), new Deck(['deck_id' => 2])];

        $data = $this->createStub(DeckData::class);
        $data->method('retrieve')->willReturn($list);

        $this->assertSame($list, (new DeckRepository($data))->get());
    }

    public function testGetWithUnknownIdReturnsEmptyArrayNotSingle(): void
    {
        $data = $this->createStub(DeckData::class);
        $data->method('retrieve')->willReturn([]);

        $this->assertSame([], (new DeckRepository($data))->get(404));
    }

    // ── PendingSpreadRepository::approve() transaction ──────────

    public function testApproveCopiesToSpreadsAndDeletesInsideTransaction(): void
    {
        $pending = new PendingSpread([
            'pending_id' => 7,
            'name'       => 'Submitted Spread',
            'card_count' => 2,
            'positions'  => [['order' => 1]],
        ]);
        $created = new Spread(['spread_id' => 42, 'name' => 'Submitted Spread']);

        $data = $this->createMock(PendingSpreadData::class);
        $data->expects($this->once())->method('retrieve')->with(7)->willReturn([$pending]);
        $data->expects($this->once())->method('delete')->with(7)->willReturn(true);

        $spreads = $this->createMock(SpreadRepository::class);
        $spreads->expects($this->once())->method('create')->willReturn($created);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('commit')->willReturn(true);
        $pdo->expects($this->never())->method('rollBack');

        $repo   = new PendingSpreadRepository($data, $spreads, $pdo);
        $result = $repo->approve(7);

        $this->assertSame($created, $result);
    }

    public function testApproveReturnsNullWhenSubmissionMissing(): void
    {
        $data = $this->createMock(PendingSpreadData::class);
        $data->expects($this->once())->method('retrieve')->with(99)->willReturn([]);
        $data->expects($this->never())->method('delete');

        $spreads = $this->createMock(SpreadRepository::class);
        $spreads->expects($this->never())->method('create');

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('beginTransaction');

        $this->assertNull((new PendingSpreadRepository($data, $spreads, $pdo))->approve(99));
    }

    public function testApproveRollsBackWhenSpreadCreationFails(): void
    {
        $pending = new PendingSpread(['pending_id' => 7, 'name' => 'X']);

        $data = $this->createMock(PendingSpreadData::class);
        $data->expects($this->once())->method('retrieve')->with(7)->willReturn([$pending]);
        $data->expects($this->never())->method('delete');

        $spreads = $this->createStub(SpreadRepository::class);
        $spreads->method('create')->willReturn(null); // creation fails

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $pdo->expects($this->never())->method('commit');

        $this->assertNull((new PendingSpreadRepository($data, $spreads, $pdo))->approve(7));
    }

    // ── ContactRepository ────────────────────────────────────────

    public function testContactGetDelegatesToDataRetrieve(): void
    {
        $contacts = [new Contact(['contact_id' => 1]), new Contact(['contact_id' => 2])];

        $data = $this->createMock(ContactData::class);
        $data->expects($this->once())->method('retrieve')->with(null)->willReturn($contacts);

        $this->assertSame($contacts, (new ContactRepository($data))->get());
    }

    public function testContactGetUnreadOnlyPassesTrue(): void
    {
        $contacts = [new Contact(['contact_id' => 1])];

        $data = $this->createMock(ContactData::class);
        $data->expects($this->once())->method('retrieve')->with(true)->willReturn($contacts);

        $this->assertSame($contacts, (new ContactRepository($data))->get(true));
    }

    public function testContactCreateDelegatesToDataStore(): void
    {
        $contact = new Contact(['contact_id' => 5]);
        $payload = ['name' => 'Bob', 'email' => 'bob@x.com', 'message' => 'Hi'];

        $data = $this->createMock(ContactData::class);
        $data->expects($this->once())->method('store')->with($payload)->willReturn($contact);

        $this->assertSame($contact, (new ContactRepository($data))->create($payload));
    }

    public function testContactMarkReadDelegatesToData(): void
    {
        $data = $this->createMock(ContactData::class);
        $data->expects($this->once())->method('markRead')->with(3, true)->willReturn(true);

        $this->assertTrue((new ContactRepository($data))->markRead(3, true));
    }

    public function testContactCountUnreadDelegatesToData(): void
    {
        $data = $this->createMock(ContactData::class);
        $data->expects($this->once())->method('countUnread')->willReturn(7);

        $this->assertSame(7, (new ContactRepository($data))->countUnread());
    }
}
