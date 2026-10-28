<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\UserController;
use Tarot\Utility\DbSessionHandler;
use Tarot\Utility\Session;

/**
 * The real account lifecycle over HTTP, sessions included: register, activate,
 * sign in, see yourself, sign out. With no SMTP configured outside production,
 * the activation link comes back in the response, as it does in dev.
 */
#[CoversClass(UserController::class)]
#[CoversClass(Session::class)]
#[CoversClass(DbSessionHandler::class)]
final class SignInFlowTest extends HttpTestCase
{
    private const string EMAIL = 'reader@example.test';

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = 'test-only-' . bin2hex(random_bytes(8));
    }

    public function testRegisterActivateSignInAndOut(): void
    {
        $registered = $this->assertJsonResponse(201, $this->post('/auth/register', [
            'email' => self::EMAIL, 'display_name' => 'Reader', 'password' => $this->password,
        ]));
        $this->assertIsString($registered['activation_link']);

        $this->assertSame(
            ['error' => 'Your account is not activated yet. Please use the activation link sent to your email.'],
            $this->assertJsonResponse(403, $this->signIn($this->password))
        );

        parse_str((string)parse_url($registered['activation_link'], PHP_URL_QUERY), $query);
        $this->assertJsonResponse(200, $this->post('/auth/activate', ['token' => $query['token'] ?? '']));

        $this->assertSame(
            ['error' => 'Invalid email or password.'],
            $this->assertJsonResponse(401, $this->signIn('not-' . $this->password))
        );

        $signedIn = $this->assertJsonResponse(200, $this->signIn($this->password));
        $this->assertTrue($signedIn['success']);
        $this->assertIsArray($signedIn['user']);
        $this->assertSame(self::EMAIL, $signedIn['user']['email']);
        $this->assertArrayNotHasKey('password_hash', $signedIn['user']);

        $me = $this->assertJsonResponse(200, $this->get('/auth/me'));
        $this->assertIsArray($me['user']);
        $this->assertSame('Reader', $me['user']['display_name']);

        $this->assertJsonResponse(200, $this->post('/auth/logout'));
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/auth/me')));
    }

    public function testSigningInIssuesANewSessionId(): void
    {
        $this->post('/auth/register', ['email' => self::EMAIL, 'display_name' => 'Reader', 'password' => $this->password]);
        $this->db->exec('UPDATE users SET is_active = 1');

        // A visitor's session from before signing in, e.g. one an attacker planted.
        $this->assertJsonResponse(401, $this->get('/auth/me'));
        $before = $this->sessionCookie();
        $this->assertNotNull($before);

        $this->assertJsonResponse(200, $this->signIn($this->password));
        $this->assertNotSame($before, $this->sessionCookie());

        // The old id was deleted, not promoted.
        $this->useSessionCookie($before);
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testADuplicateAccountIsRefused(): void
    {
        $account = ['email' => self::EMAIL, 'display_name' => 'Reader', 'password' => $this->password];
        $this->assertJsonResponse(201, $this->post('/auth/register', $account));

        $again = $this->assertJsonResponse(422, $this->post('/auth/register', $account));

        $this->assertSame(
            ['errors' => ['An account with that email address already exists.', 'That display name is already taken.']],
            $again
        );
    }

    private function signIn(string $password): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/auth/login', ['email' => self::EMAIL, 'password' => $password]);
    }
}
