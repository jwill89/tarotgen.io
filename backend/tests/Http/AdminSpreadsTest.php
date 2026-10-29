<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminSpreadController;
use Tarot\Data\FavoriteSpreadData;
use Tarot\Data\PendingSpreadData;
use Tarot\Data\SpreadData;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Structure\Spread;

/**
 * The public spreads as an admin manages them, and the queue of visitor
 * submissions: approving one publishes it, rejecting one drops it.
 */
#[CoversClass(AdminSpreadController::class)]
#[CoversClass(SpreadRepository::class)]
#[CoversClass(SpreadData::class)]
#[CoversClass(PendingSpreadRepository::class)]
#[CoversClass(PendingSpreadData::class)]
final class AdminSpreadsTest extends HttpTestCase
{
    private const array POSITION = ['order' => 1, 'title' => 'Past', 'x' => 25, 'y' => 50, 'rotation' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
    }

    public function testACreatedSpreadIsPublicWithItsPositionsCleanedUp(): void
    {
        $response = $this->post('/admin/spreads', [
            'name' => 'Three Card',
            'description' => 'Past, present, future',
            'positions' => [
                ['order' => 1, 'title' => ' Past ', 'x' => -10, 'y' => 50, 'rotation' => -90],
                ['order' => 2, 'title' => 'Present', 'x' => 50, 'y' => 150, 'rotation' => 450],
                'not a position',
            ],
        ]);

        $spread = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/admin/spreads', 201, $spread);
        $this->assertSame(2, $spread['card_count']);
        $this->assertSame([
            ['order' => 1, 'title' => 'Past', 'x' => 0, 'y' => 50, 'rotation' => 270],
            ['order' => 2, 'title' => 'Present', 'x' => 50, 'y' => 100, 'rotation' => 90],
        ], $spread['positions']);

        $list = $this->assertJsonResponse(200, $this->get('/admin/spreads'));
        $this->assertDocumented('get', '/admin/spreads', 200, $list);
        $this->assertSame(['Three Card'], array_column($list, 'name'));
        $this->assertSame(['Three Card'], array_column($this->assertJsonResponse(200, $this->get('/spreads')), 'name'));
    }

    public function testNewPositionsBringTheCardCountAlongUnlessItIsGiven(): void
    {
        $spread = $this->spread('Three Card', 3);

        $renamed = $this->assertJsonResponse(200, $this->request('PUT', "/admin/spreads/{$spread->spread_id}", [
            'name' => 'One Card', 'positions' => [self::POSITION],
        ]));
        $this->assertDocumented('put', '/admin/spreads/{spread_id}', 200, $renamed);
        $this->assertSame(['One Card', 1], [$renamed['name'], $renamed['card_count']]);

        $explicit = $this->assertJsonResponse(200, $this->request('PUT', "/admin/spreads/{$spread->spread_id}", [
            'positions' => [self::POSITION], 'card_count' => 5,
        ]));
        $this->assertSame(5, $explicit['card_count']);
    }

    public function testAnUpdateWithNothingToChangeOrForNoSpreadIsNotFound(): void
    {
        $spread = $this->spread('Three Card', 3);
        $notFound = ['error' => 'Spread not found or no valid fields'];

        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PUT', "/admin/spreads/{$spread->spread_id}", ['spread_id' => 9])));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PUT', '/admin/spreads/999', ['name' => 'Ghost'])));
        $this->assertSame([['spread_id' => $spread->spread_id, 'name' => 'Three Card']], $this->rows('SELECT spread_id, name FROM spreads'));
    }

    public function testDeletingASpreadDropsTheFavouritesThatPointAtIt(): void
    {
        $doomed = $this->spread('Three Card', 3);
        $kept = $this->spread('Celtic Cross', 10);
        $fan = $this->createUser('fan@example.test');
        $favorites = $this->service(FavoriteSpreadData::class);
        $favorites->add($fan->user_id, 'public', $doomed->spread_id);
        $favorites->add($fan->user_id, 'public', $kept->spread_id);
        // A personal spread that happens to share the id isn't touched.
        $favorites->add($fan->user_id, 'personal', $doomed->spread_id);

        $this->assertSame(204, $this->request('DELETE', "/admin/spreads/{$doomed->spread_id}")->getStatusCode());

        $this->assertSame(['Celtic Cross'], array_column($this->rows('SELECT name FROM spreads'), 'name'));
        $this->assertSame(
            [['spread_type' => 'personal', 'spread_id' => $doomed->spread_id], ['spread_type' => 'public', 'spread_id' => $kept->spread_id]],
            $this->rows('SELECT spread_type, spread_id FROM user_favorite_spreads ORDER BY spread_type')
        );
    }

    public function testApprovingASubmissionPublishesItAndEmptiesItsQueueSlot(): void
    {
        $submitter = $this->createUser('maker@example.test');
        $pending = $this->service(PendingSpreadData::class)->store([
            'name' => 'Crossroads', 'description' => 'Two paths', 'positions' => [self::POSITION, ['order' => 2, 'title' => 'Future']],
        ], $submitter->user_id);
        $this->assertNotNull($pending);
        $queue = $this->assertJsonResponse(200, $this->get('/admin/pending-spreads'));
        $this->assertSame([['Crossroads', 'maker', $submitter->user_id]], array_map(
            static fn(mixed $p): array => is_array($p) ? [$p['name'], $p['submitter'], $p['user_id']] : [],
            $queue
        ));

        $spread = $this->assertJsonResponse(201, $this->post("/admin/pending-spreads/{$pending->pending_id}/approve"));

        $this->assertDocumented('post', '/admin/pending-spreads/{pending_id}/approve', 201, $spread);
        $this->assertSame(['Crossroads', 'Two paths', 2], [$spread['name'], $spread['description'], $spread['card_count']]);
        $this->assertSame(['Past', 'Future'], array_column($spread['positions'], 'title'));
        $this->assertSame(0, $this->rowCount('pending_spreads'));
        $this->assertSame(['Crossroads'], array_column($this->assertJsonResponse(200, $this->get('/spreads')), 'name'));
    }

    public function testApprovingASubmissionThatIsGoneIsNotFound(): void
    {
        $this->assertSame(['error' => 'Pending spread not found'], $this->assertJsonResponse(404, $this->post('/admin/pending-spreads/999/approve')));
        $this->assertSame(0, $this->rowCount('spreads'));
    }

    public function testASpreadNeedsAName(): void
    {
        $required = ['error' => 'Name is required'];

        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/admin/spreads', ['positions' => [self::POSITION]])));
        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/admin/spreads', ['name' => '  ', 'positions' => [self::POSITION]])));
        $this->assertSame(0, $this->rowCount('spreads'));
    }

    public function testARenameCannotBlankTheName(): void
    {
        $spread = $this->spread('Three Card', 3);
        $required = ['error' => 'Name is required'];

        foreach (['', '  ', null] as $blank) {
            $response = $this->request('PUT', "/admin/spreads/{$spread->spread_id}", ['name' => $blank, 'positions' => [self::POSITION]]);
            $this->assertSame($required, $this->assertJsonResponse(400, $response), var_export($blank, true));
        }

        $this->assertSame([['name' => 'Three Card', 'card_count' => 3]], $this->rows('SELECT name, card_count FROM spreads'));
        // Leaving the name out changes the rest, as before.
        $this->assertSame(1, $this->assertJsonResponse(200, $this->request('PUT', "/admin/spreads/{$spread->spread_id}", ['positions' => [self::POSITION]]))['card_count']);
    }

    public function testRejectingASubmissionDropsItWithoutPublishing(): void
    {
        $pending = $this->service(PendingSpreadData::class)->store(['name' => 'Crossroads', 'positions' => [self::POSITION]]);
        $this->assertNotNull($pending);

        $this->assertSame(204, $this->request('DELETE', "/admin/pending-spreads/{$pending->pending_id}")->getStatusCode());

        $this->assertSame(0, $this->rowCount('pending_spreads'));
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/spreads')));
    }

    public function testSpreadAdministrationNeedsAnAdmin(): void
    {
        $pending = $this->service(PendingSpreadData::class)->store(['name' => 'Crossroads', 'positions' => [self::POSITION]]);
        $this->assertNotNull($pending);
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $this->assertJsonResponse(401, $this->get('/admin/pending-spreads'));
        $this->assertJsonResponse(401, $this->post("/admin/pending-spreads/{$pending->pending_id}/approve"));
        $this->assertJsonResponse(401, $this->post('/admin/spreads', ['name' => 'Mine', 'positions' => [self::POSITION]]));

        $this->assertSame(1, $this->rowCount('pending_spreads'));
        $this->assertSame(0, $this->rowCount('spreads'));
    }

    private function spread(string $name, int $cards): Spread
    {
        $spread = $this->service(SpreadData::class)->store([
            'name' => $name, 'positions' => array_fill(0, $cards, self::POSITION),
        ]);
        $this->assertNotNull($spread);

        return $spread;
    }
}
