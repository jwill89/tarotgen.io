<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminChangelogController;
use Routes\Internal\ChangelogController;

/**
 * One admin resource end to end: an admin writes a changelog entry, the public
 * changelog shows it, and only an admin can change it.
 */
#[CoversClass(AdminChangelogController::class)]
#[CoversClass(ChangelogController::class)]
final class AdminChangelogTest extends HttpTestCase
{
    public function testTheAdminListShowsEveryEntryNewestFirst(): void
    {
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
        foreach (['2026-09-01' => 'Older', '2026-10-28' => 'Newest', '2026-10-01' => 'Middle'] as $date => $title) {
            $this->assertJsonResponse(201, $this->post('/admin/changelog', ['title' => $title, 'body' => '', 'entry_date' => $date]));
        }

        $list = $this->assertJsonResponse(200, $this->get('/admin/changelog'));

        $this->assertDocumented('get', '/admin/changelog', 200, $list);
        $this->assertSame(['Newest', 'Middle', 'Older'], array_column($list, 'title'));
    }

    public function testAnAdminCreatesEditsAndDeletesAnEntry(): void
    {
        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);

        $created = $this->assertJsonResponse(201, $this->post('/admin/changelog', [
            'title' => 'Card reports', 'body' => 'Report a card from the lightbox.', 'entry_date' => '2026-10-28',
        ]));
        $this->assertSame('Card reports', $created['title']);
        $id = $created['entry_id'];
        $this->assertIsInt($id);

        $public = $this->assertJsonResponse(200, $this->get('/changelog'));
        $this->assertSame([$created], $public);
        $this->assertSame($created, $this->assertJsonResponse(200, $this->get("/changelog/{$id}")));

        $updated = $this->assertJsonResponse(200, $this->request('PUT', "/admin/changelog/{$id}", ['title' => 'Card reports, with reasons']));
        $this->assertSame('Card reports, with reasons', $updated['title']);
        $this->assertSame($created['body'], $updated['body']);

        $this->assertSame(204, $this->request('DELETE', "/admin/changelog/{$id}")->getStatusCode());
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/changelog')));
        $this->assertSame(['error' => 'InvalidEntryID'], $this->assertJsonResponse(404, $this->get("/changelog/{$id}")));
    }

    public function testUpdatingAMissingEntryIsA404(): void
    {
        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);

        $response = $this->request('PUT', '/admin/changelog/999', ['title' => 'Nothing here']);

        $this->assertSame(['error' => 'Changelog entry not found or no valid fields'], $this->assertJsonResponse(404, $response));
    }

    public function testAnEntryNeedsATitle(): void
    {
        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);
        $required = ['error' => 'Title is required'];

        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/admin/changelog', ['body' => 'No title'])));
        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/admin/changelog', ['title' => '  ', 'body' => 'Blank title'])));
        $this->assertSame(0, $this->rowCount('changelog'));

        $created = $this->assertJsonResponse(201, $this->post('/admin/changelog', ['title' => 'Kept', 'body' => 'Before']));
        $id = $created['entry_id'];
        $this->assertIsInt($id);

        $this->assertSame($required, $this->assertJsonResponse(400, $this->request('PUT', "/admin/changelog/{$id}", ['title' => '', 'body' => 'After'])));
        // Leaving the title out keeps it.
        $edited = $this->assertJsonResponse(200, $this->request('PUT', "/admin/changelog/{$id}", ['body' => 'After']));
        $this->assertSame(['Kept', 'After'], [$edited['title'], $edited['body']]);
    }

    public function testOnlyAnAdminCanWrite(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);

        $this->assertJsonResponse(401, $this->post('/admin/changelog', ['title' => 'Not mine to write']));
        $this->assertJsonResponse(401, $this->request('DELETE', '/admin/changelog/1'));

        $this->assertSame(0, $this->rowCount('changelog'));
    }
}
