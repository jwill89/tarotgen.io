<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tarot\Utility\BearerToken;
use Tarot\Utility\LoopbackUri;
use Tarot\Utility\Pkce;

/**
 * The pieces both plugin flows (the account link and the draw key) share: the
 * PKCE check, the loopback-only redirect, and reading the Bearer credential.
 */
#[CoversClass(Pkce::class)]
#[CoversClass(LoopbackUri::class)]
#[CoversClass(BearerToken::class)]
final class PluginFlowHelpersTest extends TestCase
{
    public function testTheS256ChallengeIsRfc7636s(): void
    {
        // RFC 7636, Appendix B.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challengeFor($verifier));
        $this->assertTrue(Pkce::verifies($verifier, 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        $this->assertFalse(Pkce::verifies($verifier . 'x', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        // A `plain` challenge (the verifier itself) never matches.
        $this->assertFalse(Pkce::verifies($verifier, $verifier));
    }

    /**
     * @return iterable<string,array{string,bool}>
     */
    public static function redirects(): iterable
    {
        yield '127.0.0.1 with a port' => ['http://127.0.0.1:51234/callback', true];
        yield 'localhost' => ['http://localhost:5000/cb', true];
        yield 'IPv6 loopback' => ['http://[::1]:5000/cb', true];
        yield 'no path' => ['http://127.0.0.1', true];
        yield 'upper case' => ['HTTP://LOCALHOST/cb', true];
        yield 'https' => ['https://127.0.0.1/cb', false];
        yield 'another host' => ['http://evil.example/cb', false];
        yield 'a look-alike subdomain' => ['http://127.0.0.1.evil.example/cb', false];
        yield 'loopback as the userinfo' => ['http://127.0.0.1@evil.example/cb', false];
        yield 'a backslash before the userinfo' => ['http://evil.example\@127.0.0.1/cb', false];
        yield 'no scheme' => ['//127.0.0.1/cb', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('redirects')]
    public function testOnlyAPlainHttpLoopbackAddressIsALoopbackRedirect(string $url, bool $loopback): void
    {
        $this->assertSame($loopback, LoopbackUri::isLoopback($url));
    }

    public function testTheCodeIsAddedToTheRedirectsOwnQuery(): void
    {
        $this->assertSame('http://127.0.0.1/cb?code=a%2Bb&state=s', LoopbackUri::withQuery('http://127.0.0.1/cb', ['code' => 'a+b', 'state' => 's']));
        $this->assertSame('http://127.0.0.1/cb?x=1&code=c', LoopbackUri::withQuery('http://127.0.0.1/cb?x=1', ['code' => 'c']));
    }

    public function testTheBearerCredentialIsReadFromTheAuthorizationHeader(): void
    {
        $this->assertSame('tg_dk_abc', BearerToken::from('Bearer tg_dk_abc'));
        $this->assertSame('tg_pat_abc', BearerToken::from('  bearer   tg_pat_abc  '));
        $this->assertNull(BearerToken::from(''));
        $this->assertNull(BearerToken::from('Bearer'));
        $this->assertNull(BearerToken::from('Bearer two parts'));
        $this->assertNull(BearerToken::from('Basic dXNlcjpwYXNz'));
    }
}
