<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use DI\Container;
use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Http\Factory\DecoratedServerRequestFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tarot\Repository\UserRepository;
use Tarot\Structure\User;
use Tarot\Utility\Session;

/**
 * Dispatches PSR-7 requests through the real app — api/app.php's middleware,
 * routing, controllers, services and Data layer — against an in-memory SQLite
 * database built from db/schema.sql (the production schema), foreign keys on as in
 * production.
 *
 * Each test is one browser: the session id a request leaves behind goes out with
 * the next one, as the cookie would, and the session itself is stored in the test
 * database by DbSessionHandler, so sign-in, logout and strict-mode id checks run
 * for real. Nothing here touches the developer's database or .env.
 */
abstract class HttpTestCase extends TestCase
{
    protected const string HOST = 'tarotgen.test';

    /**
     * Every setting the app reads, blanked for each test ('' counts as unset) so the
     * developer's shell can't change the outcome: no SMTP, no Turnstile, no OAuth,
     * not production.
     */
    private const array ENV_KEYS = [
        'APP_ENV', 'APP_URL', 'APP_ORIGIN', 'CSP_MODE', 'SMTP_HOST',
        'CLOUDFLARE_TURNSTILE_SITEKEY', 'CLOUDFLARE_TURNSTILE_SECRET',
        'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI',
    ];

    protected PDO $db;

    private Container $container;

    /** @var App<ContainerInterface|null>|null Built on first use, so a test can set env first. */
    private ?App $app = null;

    /** What the browser's session cookie holds; null before any session exists. */
    private ?string $sessionId = null;

    /**
     * The rate limiters are file-backed in the system temp dir and keyed by client
     * IP, so each test gets its own documentation-range address and can't trip over
     * another test's (or the local dev server's) counters.
     */
    private string $clientIp;

    /** @var array<string,mixed> */
    private array $savedEnv = [];

    private string|false $savedErrorLog = false;

    private string $errorLog;

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = '';
        }

        // Expected failures (Slim's error logging, the mailer reporting that SMTP is
        // unconfigured) would otherwise print to the test output.
        $this->errorLog = (string)tempnam(sys_get_temp_dir(), 'tarot-http-log');
        $this->savedErrorLog = ini_set('error_log', $this->errorLog);

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string)file_get_contents(dirname(__DIR__, 2) . '/db/schema.sql'));
        $this->db->exec('PRAGMA foreign_keys = ON');

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions(dirname(__DIR__, 2) . '/api/dependencies.php');
        $builder->addDefinitions([PDO::class => $this->db]);
        $this->container = $builder->build();

        // app.php does this too; doing it here as well means a session started
        // before the first request (signInAs) can never reach the real database.
        $db = $this->db;
        Session::useDatabase(static fn(): PDO => $db);

        $this->clientIp = '2001:db8::' . bin2hex(random_bytes(2)) . ':' . bin2hex(random_bytes(2));
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        ini_set('error_log', $this->savedErrorLog === false ? '' : $this->savedErrorLog);
        @unlink($this->errorLog);
    }

    /** Set an env value the app reads; applies from the next request on. */
    protected function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $this->app = null;
    }

    /**
     * A service from the app's container (wired to the test database).
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        return $this->container->get($id);
    }

    /**
     * @param array<string,mixed>|null $json  Sent as the JSON request body.
     * @param array<string,string>     $headers
     */
    protected function request(string $method, string $path, ?array $json = null, array $headers = []): ResponseInterface
    {
        $request = new DecoratedServerRequestFactory(new ServerRequestFactory())
            ->createServerRequest($method, 'https://' . self::HOST . '/api' . $path, ['REMOTE_ADDR' => $this->clientIp])
            ->withHeader('Host', self::HOST)
            ->withHeader('Accept', 'application/json');

        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new StreamFactory()->createStream(json_encode($json, JSON_THROW_ON_ERROR)));
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        session_id($this->sessionId ?? '');

        try {
            return $this->app()->handle($request);
        } finally {
            $this->endRequest();
        }
    }

    /** @param array<string,string> $headers */
    protected function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * @param array<string,mixed>  $json
     * @param array<string,string> $headers
     */
    protected function post(string $path, array $json = [], array $headers = []): ResponseInterface
    {
        return $this->request('POST', $path, $json, $headers);
    }

    /**
     * Assert the status (showing the body when it differs) and return the decoded
     * JSON body.
     *
     * @return array<mixed>
     */
    protected function assertJsonResponse(int $status, ResponseInterface $response): array
    {
        $body = (string)$response->getBody();
        $this->assertSame($status, $response->getStatusCode(), "Unexpected status; body: {$body}");
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /**
     * Read the test database directly, to check what a request stored.
     *
     * @return list<array<string,mixed>>
     */
    protected function rows(string $sql): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    protected function rowCount(string $table): int
    {
        return count($this->rows("SELECT 1 FROM {$table}"));
    }

    /** A user with a placeholder hash: it can't sign in by password, only via signInAs(). */
    protected function createUser(string $email, bool $admin = false, bool $active = true): User
    {
        $users = $this->service(UserRepository::class);
        $user = $users->create($email, strstr($email, '@', true) ?: $email, 'not-a-password-hash');
        $this->assertNotNull($user);

        if ($active) {
            $users->activate($user->user_id);
        }
        if ($admin) {
            $users->setAdmin($user->user_id, true);
        }

        $fresh = $users->findById($user->user_id);
        $this->assertNotNull($fresh);
        return $fresh;
    }

    /** Leave the browser signed in as $userId, as a successful login would. */
    protected function signInAs(int $userId): void
    {
        session_id($this->sessionId ?? '');
        Session::start();
        Session::regenerate();
        $_SESSION['user_id'] = $userId;
        $this->endRequest();
    }

    /** The session id the browser's cookie holds, if any. */
    protected function sessionCookie(): ?string
    {
        return $this->sessionId;
    }

    /** Send $sessionId as the cookie from now on (null: a browser with no cookie). */
    protected function useSessionCookie(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /** @return App<ContainerInterface|null> */
    private function app(): App
    {
        return $this->app ??= (require dirname(__DIR__, 2) . '/api/app.php')($this->container, false);
    }

    /**
     * What PHP does when a request ends: write and close the session. The browser
     * keeps whatever id it ended on (a login or logout regenerates it).
     */
    private function endRequest(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->sessionId = session_id() ?: null;
            session_write_close();
        }
        $_SESSION = [];
    }
}
