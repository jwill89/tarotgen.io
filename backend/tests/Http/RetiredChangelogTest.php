<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The hand-entered changelog is retired (AUDIT §9.13): the release notes are
 * CHANGELOG.md, built into the admin dashboard's version dialog. Its routes are
 * gone, for an admin too, so a stale SPA or a bookmark gets the API's 404 rather
 * than a table the new schema no longer has.
 */
#[CoversNothing]
final class RetiredChangelogTest extends HttpTestCase
{
    public function testItsPublicAndAdminRoutesAnswer404EvenForAnAdmin(): void
    {
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $requests = [
            ['GET', '/changelog', null],
            ['GET', '/changelog/1', null],
            ['GET', '/admin/changelog', null],
            ['POST', '/admin/changelog', ['title' => 'Launch', 'body' => 'Hello']],
            ['PUT', '/admin/changelog/1', ['title' => 'Launch']],
            ['DELETE', '/admin/changelog/1', null],
        ];
        foreach ($requests as [$method, $path, $json]) {
            $body = $this->assertJsonResponse(404, $this->request($method, $path, $json));
            $this->assertSame('Not found.', $body['error'] ?? null, "{$method} {$path}");
        }
    }

    public function testTheSchemaHasNoChangelogTable(): void
    {
        $this->assertSame([], $this->rows("SELECT name FROM sqlite_master WHERE name = 'changelog'"));
    }
}
