<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tarot\Exception\ApiException;

#[CoversClass(ApiException::class)]
final class ApiExceptionTest extends TestCase
{
    public function testCarriesMessageAndStatusCode(): void
    {
        $e = new ApiException('InvalidDeckID', 404);

        $this->assertSame('InvalidDeckID', $e->getMessage());
        $this->assertSame(404, $e->getStatusCode());
    }

    public function testStatusCodeDefaultsTo400(): void
    {
        $e = new ApiException('Bad input');

        $this->assertSame(400, $e->getStatusCode());
    }

    public function testIsRuntimeException(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new ApiException('x'));
    }

    public function testPreservesPreviousThrowable(): void
    {
        $previous = new RuntimeException('root cause');
        $e        = new ApiException('wrapped', 500, $previous);

        $this->assertSame($previous, $e->getPrevious());
    }
}
