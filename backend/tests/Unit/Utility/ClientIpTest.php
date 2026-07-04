<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tarot\Utility\ClientIp;

/**
 * Verifies the Cloudflare-aware client IP resolution: the CF-Connecting-IP header
 * is honoured only when the transport peer is genuinely a Cloudflare edge, and we
 * fall back to REMOTE_ADDR everywhere else.
 */
#[CoversClass(ClientIp::class)]
final class ClientIpTest extends TestCase
{
    private function request(string $remoteAddr, ?string $cfConnectingIp = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://tarotgen.io/api/readings/abc',
            $remoteAddr === '' ? [] : ['REMOTE_ADDR' => $remoteAddr]
        );

        if ($cfConnectingIp !== null) {
            $request = $request->withHeader('CF-Connecting-IP', $cfConnectingIp);
        }

        return $request;
    }

    public function testTrustsCfHeaderFromCloudflareEdge(): void
    {
        // 173.245.48.0/20 is a Cloudflare range.
        $request = $this->request('173.245.48.9', '203.0.113.7');
        $this->assertSame('203.0.113.7', ClientIp::resolve($request));
    }

    public function testTrustsCfHeaderFromCloudflareIpv6Edge(): void
    {
        // 2400:cb00::/32 is a Cloudflare v6 range.
        $request = $this->request('2400:cb00::1', '203.0.113.7');
        $this->assertSame('203.0.113.7', ClientIp::resolve($request));
    }

    public function testIgnoresCfHeaderFromNonCloudflarePeer(): void
    {
        // A direct origin hit: the peer isn't Cloudflare, so the header is untrusted.
        $request = $this->request('198.51.100.4', '203.0.113.7');
        $this->assertSame('198.51.100.4', ClientIp::resolve($request));
    }

    public function testCidrBoundaryIsRespected(): void
    {
        // 173.245.64.1 sits just outside 173.245.48.0/20 (which ends at .63.255).
        $request = $this->request('173.245.64.1', '203.0.113.7');
        $this->assertSame('173.245.64.1', ClientIp::resolve($request));
    }

    public function testFallsBackToRemoteAddrWhenCfHeaderMissing(): void
    {
        $request = $this->request('173.245.48.9');
        $this->assertSame('173.245.48.9', ClientIp::resolve($request));
    }

    public function testFallsBackWhenCfHeaderIsNotAValidIp(): void
    {
        $request = $this->request('173.245.48.9', 'not-an-ip');
        $this->assertSame('173.245.48.9', ClientIp::resolve($request));
    }

    public function testUnknownWhenNoRemoteAddr(): void
    {
        $this->assertSame('unknown', ClientIp::resolve($this->request('')));
    }
}
