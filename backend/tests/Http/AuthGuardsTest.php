<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Middleware\AccountAuth;
use Routes\Middleware\AdminAuth;
use Routes\Middleware\UserAuth;
use Tarot\Repository\UserRepository;
use Tarot\Service\PluginTokenService;

/**
 * The three auth middleware, through the routes they guard: who gets in, and
 * the 401 body everyone else gets.
 */
#[CoversClass(AdminAuth::class)]
#[CoversClass(UserAuth::class)]
#[CoversClass(AccountAuth::class)]
final class AuthGuardsTest extends HttpTestCase
{
    public function testAdminRoutesNeedAnActiveAdminAccount(): void
    {
        $this->assertSame(['error' => 'Unauthorized'], $this->assertJsonResponse(401, $this->get('/admin/auth-check')));

        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->assertSame(['error' => 'Unauthorized'], $this->assertJsonResponse(401, $this->get('/admin/auth-check')));

        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);
        $this->assertSame(['authenticated' => true], $this->assertJsonResponse(200, $this->get('/admin/auth-check')));
    }

    public function testRevokingAdminTakesEffectOnTheNextRequest(): void
    {
        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);
        $this->assertJsonResponse(200, $this->get('/admin/auth-check'));

        $this->service(UserRepository::class)->setAdmin($admin->user_id, false);

        $this->assertJsonResponse(401, $this->get('/admin/auth-check'));
    }

    public function testAnInactiveAccountIsNotSignedIn(): void
    {
        $pending = $this->createUser('pending@example.test', admin: true, active: false);
        $this->signInAs($pending->user_id);

        $this->assertJsonResponse(401, $this->get('/admin/auth-check'));
        $this->assertJsonResponse(401, $this->get('/account/spreads'));
        $this->assertJsonResponse(401, $this->get('/account/readings'));
    }

    public function testSessionOnlyRoutesNeedASignedInUser(): void
    {
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/account/spreads')));

        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/account/spreads')));
    }

    public function testASessionForADeletedAccountIsRejected(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->service(UserRepository::class)->delete($reader->user_id);

        $this->assertJsonResponse(401, $this->get('/account/spreads'));
    }

    public function testSigningInInOneBrowserDoesNotSignInAnother(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->assertJsonResponse(200, $this->get('/account/spreads'));

        $this->useSessionCookie(null);

        $this->assertJsonResponse(401, $this->get('/account/spreads'));
    }

    public function testAPluginTokenOpensTheAccountReadRoutes(): void
    {
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/account/readings')));

        $reader = $this->createUser('reader@example.test');
        $token = $this->service(PluginTokenService::class)->issueToken($reader->user_id);
        $bearer = ['Authorization' => "Bearer {$token}"];

        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/account/readings', $bearer)));
        $this->assertJsonResponse(200, $this->get('/account/favorites', $bearer));
        $this->assertJsonResponse(200, $this->get('/account/favorite-decks', $bearer));
    }

    public function testAPluginTokenCannotReachSessionOnlyRoutes(): void
    {
        $reader = $this->createUser('reader@example.test');
        $token = $this->service(PluginTokenService::class)->issueToken($reader->user_id);
        $bearer = ['Authorization' => "Bearer {$token}"];

        // Least privilege: a leaked token can't manage the account or its tokens.
        $this->assertJsonResponse(401, $this->get('/account/spreads', $bearer));
        $this->assertJsonResponse(401, $this->get('/account/tokens', $bearer));
        $this->assertJsonResponse(401, $this->request('DELETE', '/account', null, $bearer));
        $this->assertJsonResponse(401, $this->get('/admin/auth-check', $bearer));
    }

    public function testUnknownAndRevokedTokensAreRejected(): void
    {
        $this->assertJsonResponse(401, $this->get('/account/readings', ['Authorization' => 'Bearer tg_not-a-real-token']));

        $reader = $this->createUser('reader@example.test');
        $token = $this->service(PluginTokenService::class)->issueToken($reader->user_id);

        // Revoked from the account page, in the browser session.
        $this->signInAs($reader->user_id);
        $tokens = $this->assertJsonResponse(200, $this->get('/account/tokens'));
        $this->assertCount(1, $tokens);
        $this->assertIsArray($tokens[0]);
        $response = $this->request('DELETE', '/account/tokens/' . $tokens[0]['id']);
        $this->assertSame(204, $response->getStatusCode());

        $this->useSessionCookie(null);
        $this->assertJsonResponse(401, $this->get('/account/readings', ['Authorization' => "Bearer {$token}"]));
    }
}
