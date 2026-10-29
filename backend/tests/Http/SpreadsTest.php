<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\SpreadController;
use Tarot\Data\PendingSpreadData;
use Tarot\Data\SpreadData;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserRepository;

/**
 * The public spreads and the submission form that feeds the review queue: open
 * to guests, limited per visitor, and never published without an admin.
 */
#[CoversClass(SpreadController::class)]
#[CoversClass(SpreadRepository::class)]
#[CoversClass(SpreadData::class)]
#[CoversClass(PendingSpreadRepository::class)]
#[CoversClass(PendingSpreadData::class)]
final class SpreadsTest extends HttpTestCase
{
    private const array POSITION = ['order' => 1, 'title' => 'Here', 'x' => 50, 'y' => 50, 'rotation' => 0];

    public function testThePublicSpreadsAreListedByName(): void
    {
        $spreads = $this->service(SpreadData::class);
        $spreads->store(['name' => 'Three Card', 'positions' => [self::POSITION, self::POSITION, self::POSITION]]);
        $spreads->store(['name' => 'Celtic Cross', 'positions' => [self::POSITION]]);

        $response = $this->get('/spreads');

        $list = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/spreads', 200, $list);
        $this->assertSame(['Celtic Cross', 'Three Card'], array_column($list, 'name'));
        $this->assertSame([1, 3], array_column($list, 'card_count'));
        $this->assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
    }

    public function testASingleSpreadOrNotFound(): void
    {
        $spread = $this->service(SpreadData::class)->store(['name' => 'Three Card', 'positions' => [self::POSITION]]);
        $this->assertNotNull($spread);

        $body = $this->assertJsonResponse(200, $this->get("/spreads/{$spread->spread_id}"));
        $this->assertDocumented('get', '/spreads/{spread_id}', 200, $body);
        $this->assertSame('Three Card', $body['name']);

        $missing = $this->get('/spreads/999');
        $this->assertSame(['error' => 'InvalidSpreadID'], $this->assertJsonResponse(404, $missing));
        $this->assertSame('', $missing->getHeaderLine('Cache-Control'));
    }

    public function testAGuestSubmissionJoinsTheQueueButNotTheCatalogue(): void
    {
        $response = $this->post('/spreads', ['name' => ' Crossroads ', 'description' => 'Two paths', 'positions' => [self::POSITION]]);

        $body = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/spreads', 201, $body);
        $this->assertSame(['success' => true], $body);
        $this->assertSame(
            [['name' => 'Crossroads', 'description' => 'Two paths', 'card_count' => 1, 'user_id' => null]],
            $this->rows('SELECT name, description, card_count, user_id FROM pending_spreads')
        );
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/spreads')));
    }

    public function testASignedInSubmissionIsCreditedToTheAccount(): void
    {
        $maker = $this->createUser('maker@example.test');
        $this->signInAs($maker->user_id);

        $this->assertJsonResponse(201, $this->post('/spreads', ['name' => 'Crossroads', 'positions' => [self::POSITION]]));

        $this->assertSame([['user_id' => $maker->user_id]], $this->rows('SELECT user_id FROM pending_spreads'));
    }

    public function testADeletedAccountsLeftoverSessionSubmitsAsAGuest(): void
    {
        $gone = $this->createUser('gone@example.test');
        $this->signInAs($gone->user_id);
        $this->service(UserRepository::class)->delete($gone->user_id);

        // Its id used to fail the foreign key, and the submission was lost to a 500.
        $this->assertJsonResponse(201, $this->post('/spreads', ['name' => 'Crossroads', 'positions' => [self::POSITION]]));

        $this->assertSame([['name' => 'Crossroads', 'user_id' => null]], $this->rows('SELECT name, user_id FROM pending_spreads'));
    }

    public function testASubmissionNeedsANameAndAtLeastOnePosition(): void
    {
        $required = ['error' => 'A spread name and at least one card position are required.'];

        foreach ([
            ['positions' => [self::POSITION]],
            ['name' => '   ', 'positions' => [self::POSITION]],
            ['name' => 'Crossroads'],
            ['name' => 'Crossroads', 'positions' => []],
            ['name' => 'Crossroads', 'positions' => 'one'],
        ] as $submission) {
            $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/spreads', $submission)));
        }
        $this->assertSame(0, $this->rowCount('pending_spreads'));
    }

    public function testAVisitorCanSubmitFiveSpreadsAnHour(): void
    {
        // Refused submissions don't use up the allowance.
        $this->assertJsonResponse(400, $this->post('/spreads', ['name' => 'Empty']));
        for ($i = 1; $i <= 5; $i++) {
            $this->assertJsonResponse(201, $this->post('/spreads', ['name' => "Spread {$i}", 'positions' => [self::POSITION]]));
        }

        $this->assertSame(
            ['error' => 'You have submitted too many spreads recently. Please try again later.'],
            $this->assertJsonResponse(429, $this->post('/spreads', ['name' => 'Spread 6', 'positions' => [self::POSITION]]))
        );
        $this->assertSame(5, $this->rowCount('pending_spreads'));
    }
}
