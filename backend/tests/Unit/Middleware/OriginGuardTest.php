<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Routes\Middleware\OriginGuard;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The Origin/Referer CSRF guard: state-changing requests must come from our own
 * host; safe methods and origin-less (non-browser) requests pass through.
 */
#[CoversClass(OriginGuard::class)]
final class OriginGuardTest extends TestCase
{
    private const string SELF = 'https://tarotgen.io';

    /** @var array<string,?string> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Isolate from any ambient APP_URL/APP_ORIGIN so tests are deterministic.
        foreach (['APP_URL', 'APP_ORIGIN'] as $k) {
            $this->savedEnv[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach (['APP_URL', 'APP_ORIGIN'] as $k) {
            if ($this->savedEnv[$k] === null) {
                unset($_ENV[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $this->savedEnv[$k];
                putenv("$k={$this->savedEnv[$k]}");
            }
        }
    }

    /**
     * Run the guard for a request to our host and return the resulting status.
     *
     * @param array<string,string> $headers
     */
    private function guardStatus(string $method, array $headers): int
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, self::SELF . '/api/thing')
            ->withHeader('Host', (string)parse_url(self::SELF, PHP_URL_HOST));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200); // "reached the controller"
            }
        };

        return (new OriginGuard())($request, $handler)->getStatusCode();
    }

    public function testSameOriginPostIsAllowed(): void
    {
        $this->assertSame(200, $this->guardStatus('POST', ['Origin' => self::SELF]));
    }

    public function testCrossOriginPostIsBlocked(): void
    {
        $this->assertSame(403, $this->guardStatus('POST', ['Origin' => 'https://evil.example.com']));
    }

    public function testSafeMethodsAreExemptEvenCrossOrigin(): void
    {
        // GET (incl. the OAuth callback) is never blocked by Origin.
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $this->assertSame(200, $this->guardStatus($method, ['Origin' => 'https://evil.example.com']), "$method should pass");
        }
    }

    public function testPostWithNoOriginOrRefererIsAllowed(): void
    {
        // Non-browser client (no ambient cookies) — not a CSRF vector.
        $this->assertSame(200, $this->guardStatus('POST', []));
    }

    public function testRefererIsUsedWhenOriginAbsent(): void
    {
        $this->assertSame(200, $this->guardStatus('POST', ['Referer' => self::SELF . '/login']));
        $this->assertSame(403, $this->guardStatus('POST', ['Referer' => 'https://evil.example.com/page']));
    }

    public function testNullOriginFallsThroughToReferer(): void
    {
        // Origin: null (sandboxed/privacy contexts) with no Referer → allowed
        // (SameSite=Lax still blocks the cookie on a cross-site POST).
        $this->assertSame(200, $this->guardStatus('POST', ['Origin' => 'null']));
    }

    public function testPortIsIgnoredWhenComparingHosts(): void
    {
        // Dev: Host carries a port, Origin's parsed host does not — still same site.
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/thing')
            ->withHeader('Host', 'localhost:80')
            ->withHeader('Origin', 'http://localhost:5173');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $this->assertSame(200, (new OriginGuard())($request, $handler)->getStatusCode());
    }

    public function testConfiguredAppOriginIsAllowed(): void
    {
        $_ENV['APP_ORIGIN'] = 'https://app.example.com';
        // A cross-host request from the configured SPA origin is permitted
        // (consistent with the CORS allow-list).
        $this->assertSame(200, $this->guardStatus('POST', ['Origin' => 'https://app.example.com']));
        // ...but an unrelated origin is still blocked.
        $this->assertSame(403, $this->guardStatus('POST', ['Origin' => 'https://evil.example.com']));
    }
}
