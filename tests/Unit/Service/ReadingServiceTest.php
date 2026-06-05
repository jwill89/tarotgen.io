<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Exception\ApiException;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Service\CardNameResolver;
use Tarot\Service\ReadingService;
use Tarot\Structure\Deck;
use Tarot\Structure\Reading;
use Tarot\Structure\Spread;
use Tarot\Structure\UserSpread;

#[CoversClass(ReadingService::class)]
final class ReadingServiceTest extends TestCase
{
    private ReadingRepository $readings;
    private DeckRepository $decks;
    private SpreadRepository $spreads;
    private UserSpreadRepository $userSpreads;
    private CardNameResolver $cardNames;

    protected function setUp(): void
    {
        // These collaborators are used purely as stubs (we assert on the
        // returned Reading, not on how they are called), so createStub() is the
        // right tool under PHPUnit 12.
        $this->readings    = $this->createStub(ReadingRepository::class);
        $this->decks       = $this->createStub(DeckRepository::class);
        $this->spreads     = $this->createStub(SpreadRepository::class);
        $this->userSpreads = $this->createStub(UserSpreadRepository::class);
        $this->cardNames   = $this->createStub(CardNameResolver::class);
    }

    private function service(): ReadingService
    {
        return new ReadingService(
            $this->readings,
            $this->decks,
            $this->spreads,
            $this->userSpreads,
            $this->cardNames,
        );
    }

    /** Echo back whatever Reading is saved, simulating a successful persist. */
    private function persistSucceeds(): void
    {
        $this->readings->method('save')->willReturnCallback(static fn(Reading $r): Reading => $r);
    }

    /** Decode the reading_info JSON of a saved reading. */
    private function decodeInfo(Reading $reading): array
    {
        return json_decode($reading->getReadingInfo(), true, 512, JSON_THROW_ON_ERROR);
    }

    // ── generate() ──────────────────────────────────────────────

    public function testGenerateThrowsWhenDeckIsInvalid(): void
    {
        $this->decks->method('get')->willReturn([]); // not a Deck

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('InvalidDeckID');

        try {
            $this->service()->generate(['deck_id' => 999]);
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    public function testGenerateDrawsRequestedNumberOfCards(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->persistSucceeds();

        $reading = $this->service()->generate(['deck_id' => 1, 'number_of_cards' => 3]);
        $info    = $this->decodeInfo($reading);

        $this->assertSame(1, $info['deck_id']);
        $this->assertCount(3, $info['draw']);
        foreach ($info['draw'] as $entry) {
            $this->assertArrayHasKey('card_id', $entry);
            $this->assertArrayHasKey('reversed', $entry);
            $this->assertArrayHasKey('card_name', $entry);
        }
    }

    public function testGenerateNeverDrawsMoreThanTheDeckHolds(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 2]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B']);
        $this->persistSucceeds();

        $reading = $this->service()->generate(['deck_id' => 1, 'number_of_cards' => 50]);

        $this->assertCount(2, $this->decodeInfo($reading)['draw']);
    }

    public function testGenerateWithoutReversalsKeepsEveryCardUpright(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->persistSucceeds();

        $reading = $this->service()->generate([
            'deck_id'         => 1,
            'number_of_cards' => 3,
            'use_reversals'   => false,
        ]);

        foreach ($this->decodeInfo($reading)['draw'] as $entry) {
            $this->assertFalse($entry['reversed']);
        }
    }

    public function testGenerateUsesSpreadCardCountAndSnapshotsLayout(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 10]));
        $this->spreads->method('get')->willReturn(new Spread([
            'spread_id'  => 4,
            'name'       => 'Two Card',
            'card_count' => 2,
            'positions'  => [['order' => 1, 'title' => 'Past'], ['order' => 2, 'title' => 'Future']],
        ]));
        $this->cardNames->method('resolve')->willReturn(array_combine(range(1, 10), array_fill(0, 10, 'X')));
        $this->persistSucceeds();

        $reading = $this->service()->generate([
            'deck_id'         => 1,
            'number_of_cards' => 9, // should be overridden by the spread
            'spread_id'       => 4,
        ]);
        $info = $this->decodeInfo($reading);

        $this->assertCount(2, $info['draw']);
        $this->assertArrayHasKey('spread', $info);
        $this->assertSame(4, $info['spread']['spread_id']);
        $this->assertCount(2, $info['spread']['positions']);
    }

    public function testGenerateUsesUserSpreadCardCountAndSnapshotsLayout(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 10]));
        $this->userSpreads->method('findById')->willReturn(new UserSpread([
            'user_spread_id' => 7,
            'name'           => 'My Personal Spread',
            'card_count'     => 3,
            'positions'      => [
                ['order' => 1, 'title' => 'Body'],
                ['order' => 2, 'title' => 'Mind'],
                ['order' => 3, 'title' => 'Spirit'],
            ],
        ]));
        $this->cardNames->method('resolve')->willReturn(array_combine(range(1, 10), array_fill(0, 10, 'X')));
        $this->persistSucceeds();

        $reading = $this->service()->generate([
            'deck_id'         => 1,
            'number_of_cards' => 9, // should be overridden by the user spread
            'user_spread_id'  => 7,
        ]);
        $info = $this->decodeInfo($reading);

        $this->assertCount(3, $info['draw']);
        $this->assertArrayHasKey('spread', $info);
        $this->assertSame('My Personal Spread', $info['spread']['name']);
        // A personal spread is snapshotted as a non-public spread (id 0).
        $this->assertSame(0, $info['spread']['spread_id']);
        $this->assertCount(3, $info['spread']['positions']);
    }

    public function testGenerateThrowsWithStatus404WhenSaveFails(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->readings->method('save')->willReturn(null);

        try {
            $this->service()->generate(['deck_id' => 1, 'number_of_cards' => 1]);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('ErrorGeneratingReading', $e->getMessage());
        }
    }

    // ── createCustom() ──────────────────────────────────────────

    public function testCreateCustomRejectsEmptyCardList(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('At least one card is required.');

        $this->service()->createCustom(['deck_id' => 1, 'cards' => []]);
    }

    public function testCreateCustomRejectsInvalidDeck(): void
    {
        $this->decks->method('get')->willReturn([]); // not a Deck

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('InvalidDeckID');

        $this->service()->createCustom([
            'deck_id' => 999,
            'cards'   => [['card_id' => 1]],
        ]);
    }

    public function testCreateCustomRejectsCardOutsideDeckRange(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 5]));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('A selected card is not part of this deck.');

        $this->service()->createCustom([
            'deck_id' => 1,
            'cards'   => [['card_id' => 99]],
        ]);
    }

    public function testCreateCustomRejectsDuplicateCards(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 5]));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Each card can only be used once in a reading.');

        $this->service()->createCustom([
            'deck_id' => 1,
            'cards'   => [['card_id' => 2], ['card_id' => 2]],
        ]);
    }

    public function testCreateCustomThrowsWhenNameCannotBeResolved(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([]); // nothing resolves

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('A selected card could not be found.');

        $this->service()->createCustom([
            'deck_id' => 1,
            'cards'   => [['card_id' => 1]],
        ]);
    }

    public function testCreateCustomBuildsOrderedDrawAndSpreadSnapshot(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([1 => 'The Fool', 3 => 'The Empress']);
        $this->persistSucceeds();

        $reading = $this->service()->createCustom([
            'deck_id' => 1,
            'name'    => 'My Physical Reading',
            'cards'   => [
                ['card_id' => 1, 'reversed' => true, 'title' => 'Start', 'x' => 10, 'y' => 20, 'rotation' => 90],
                ['card_id' => 3, 'title' => 'End'],
            ],
        ]);
        $info = $this->decodeInfo($reading);

        $this->assertSame(1, $info['deck_id']);
        $this->assertSame('My Physical Reading', $info['spread']['name']);
        $this->assertCount(2, $info['draw']);

        $this->assertSame(1, $info['draw'][0]['card_id']);
        $this->assertTrue($info['draw'][0]['reversed']);
        $this->assertSame('The Fool', $info['draw'][0]['card_name']);

        $this->assertSame(3, $info['draw'][1]['card_id']);
        $this->assertFalse($info['draw'][1]['reversed']);
        $this->assertSame('The Empress', $info['draw'][1]['card_name']);

        // First position keeps its sanitised coordinates/rotation. (x is
        // compared loosely: a whole-number float may round-trip through JSON as
        // an int.)
        $this->assertEqualsWithDelta(10.0, $info['spread']['positions'][0]['x'], 0.001);
        $this->assertSame(90, $info['spread']['positions'][0]['rotation']);
    }

    public function testCreateCustomAcceptsCardsAsJsonString(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([2 => 'The High Priestess']);
        $this->persistSucceeds();

        $reading = $this->service()->createCustom([
            'deck_id' => 1,
            'cards'   => json_encode([['card_id' => 2]]),
        ]);
        $info = $this->decodeInfo($reading);

        $this->assertCount(1, $info['draw']);
        $this->assertSame('The High Priestess', $info['draw'][0]['card_name']);
    }

    public function testCreateCustomDefaultsBlankNameToCustomReading(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([1 => 'The Fool']);
        $this->persistSucceeds();

        $reading = $this->service()->createCustom([
            'deck_id' => 1,
            'name'    => '   ',
            'cards'   => [['card_id' => 1]],
        ]);

        $this->assertSame('Custom Reading', $this->decodeInfo($reading)['spread']['name']);
    }

    public function testCreateCustomThrowsWithStatus500WhenSaveFails(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([1 => 'The Fool']);
        $this->readings->method('save')->willReturn(null);

        try {
            $this->service()->createCustom(['deck_id' => 1, 'cards' => [['card_id' => 1]]]);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
    }
}
