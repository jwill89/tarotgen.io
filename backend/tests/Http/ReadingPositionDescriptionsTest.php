<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\ReadingController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\SpreadData;
use Tarot\Service\ReadingService;

/**
 * A spread's position descriptions travel into the readings made with it, so a
 * shared reading says what each position asks — and a reader who retitles a
 * position, or re-places the cards, doesn't end up with a description that no
 * longer fits.
 */
#[CoversClass(ReadingService::class)]
#[CoversClass(ReadingController::class)]
final class ReadingPositionDescriptionsTest extends HttpTestCase
{
    private int $deckId;

    private int $spreadId;

    protected function setUp(): void
    {
        parent::setUp();

        $systems = $this->service(DeckSystemData::class);
        $system = $systems->store(['name' => 'Little Arcana', 'short_name' => 'LA', 'total_cards' => 6]);
        $this->assertNotNull($system);
        $systems->storeCards($system->deck_system_id, array_map(
            static fn(int $id): array => ['card_id' => $id, 'name' => "Card {$id}"],
            range(1, 6)
        ));
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;

        $spread = $this->service(SpreadData::class)->store([
            'name' => 'Crossroads',
            'positions' => [
                ['order' => 1, 'title' => 'Where you stand', 'x' => 50, 'y' => 70, 'description' => 'The situation as it is now'],
                ['order' => 2, 'title' => 'Left path', 'x' => 25, 'y' => 30, 'description' => 'What one choice opens'],
                ['order' => 3, 'title' => 'Right path', 'x' => 75, 'y' => 30],
            ],
        ]);
        $this->assertNotNull($spread);
        $this->spreadId = $spread->spread_id;
    }

    public function testAReadingKeepsItsSpreadsPositionDescriptions(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'spread_id' => $this->spreadId,
        ]));
        $this->assertDocumented('post', '/readings/generate', 201, $created);

        // Shared: the link shows them to anyone.
        $this->useSessionCookie(null);
        $viewed = $this->assertJsonResponse(200, $this->get("/readings/{$created['reading_id']}"));
        $this->assertDocumented('get', '/readings/{reading_id}', 200, $viewed);
        $this->assertSame(
            [
                ['Where you stand', 'The situation as it is now'],
                ['Left path', 'What one choice opens'],
                ['Right path', null],
            ],
            $this->titlesAndDescriptions($viewed)
        );
    }

    public function testARetitledPositionDropsTheSpreadsDescription(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'spread_id' => $this->spreadId,
            // A blank title keeps the spread's own, and so does the same title.
            'position_titles' => json_encode(['Where you stand', 'Career', '']),
        ]));

        $this->assertSame(
            [
                ['Where you stand', 'The situation as it is now'],
                ['Career', null],
                ['Right path', null],
            ],
            $this->titlesAndDescriptions($created)
        );
    }

    public function testRePlacingTheCardsKeepsTheDescriptionsOfPositionsLeftAsTheyWere(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'spread_id' => $this->spreadId,
        ]));
        $id = $created['reading_id'];
        $this->assertIsString($id);

        $placed = $this->assertJsonResponse(200, $this->request('PUT', "/readings/{$id}/placement", ['positions' => [
            ['title' => 'Where you stand', 'x' => 10, 'y' => 10],
            ['title' => 'A new title', 'x' => 20, 'y' => 20],
            ['title' => 'Right path', 'x' => 30, 'y' => 30, 'description' => 'Sent by the client'],
        ]]));
        $this->assertDocumented('put', '/readings/{reading_id}/placement', 200, $placed);

        $this->assertSame(
            [
                ['Where you stand', 'The situation as it is now'],
                ['A new title', null],
                // Placement takes no descriptions from the client.
                ['Right path', null],
            ],
            $this->titlesAndDescriptions($this->assertJsonResponse(200, $this->get("/readings/{$id}")))
        );
    }

    /**
     * @param array<mixed> $reading
     * @return list<array{mixed, mixed}>
     */
    private function titlesAndDescriptions(array $reading): array
    {
        $this->assertIsArray($reading['reading_info']);
        $this->assertIsArray($reading['reading_info']['spread']);
        $positions = $reading['reading_info']['spread']['positions'];
        $this->assertIsArray($positions);

        return array_values(array_map(
            static fn(mixed $p): array => is_array($p) ? [$p['title'] ?? null, $p['description'] ?? null] : [null, null],
            $positions
        ));
    }
}
