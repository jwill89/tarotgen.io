<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Routes\JsonErrorRenderer;
use RuntimeException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;

#[CoversClass(JsonErrorRenderer::class)]
final class JsonErrorRendererTest extends TestCase
{
    public function testInProductionAnUncaughtExceptionSaysNothingAboutItself(): void
    {
        $exception = new RuntimeException('SQLSTATE[HY000]: unable to open /var/lib/tarotgen/db/tarotdb.db');

        $body = $this->render($exception, false);

        $this->assertSame(['error' => 'Something went wrong on our side. Please try again.'], $body);
    }

    public function testAnHttpErrorKeepsItsClientMessage(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', '/api/nope');

        $this->assertSame(['error' => 'Not found.'], $this->render(new HttpNotFoundException($request), false));
    }

    public function testOutsideProductionTheExceptionChainIsIncluded(): void
    {
        $exception = new RuntimeException('outer', 1, new RuntimeException('inner'));

        $body = $this->render($exception, true);

        $this->assertSame('Something went wrong on our side. Please try again.', $body['error']);
        $this->assertSame(['outer', 'inner'], array_column($body['exception'], 'message'));
    }

    /** @return array<string,mixed> */
    private function render(\Throwable $exception, bool $displayErrorDetails): array
    {
        $body = json_decode(new JsonErrorRenderer()($exception, $displayErrorDetails), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        return $body;
    }
}
