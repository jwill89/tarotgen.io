<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Tarot\Data\NormalizesPositions;

#[CoversTrait(NormalizesPositions::class)]
final class NormalizesPositionsTest extends TestCase
{
    /**
     * Expose the trait's private normalizePositions() through a public shim so
     * we can exercise it in isolation.
     */
    private function subject(): object
    {
        return new class {
            use NormalizesPositions;

            public function run(mixed $positions): array
            {
                return $this->normalizePositions($positions);
            }
        };
    }

    public function testNonArrayInputReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->subject()->run('not-an-array'));
        $this->assertSame([], $this->subject()->run(null));
        $this->assertSame([], $this->subject()->run(42));
    }

    public function testNonArrayEntriesAreSkipped(): void
    {
        $result = $this->subject()->run(['nope', 123, ['order' => 1]]);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['order']);
    }

    public function testCoordinatesAreClampedToZeroHundred(): void
    {
        $result = $this->subject()->run([
            ['x' => -10, 'y' => 250],
        ]);

        $this->assertSame(0.0, $result[0]['x']);
        $this->assertSame(100.0, $result[0]['y']);
    }

    public function testCoordinatesAreRoundedToTwoDecimals(): void
    {
        $result = $this->subject()->run([
            ['x' => 12.34567, 'y' => 88.005],
        ]);

        $this->assertSame(12.35, $result[0]['x']);
        $this->assertSame(88.01, $result[0]['y']);
    }

    public function testRotationIsNormalizedIntoZeroTo359(): void
    {
        $result = $this->subject()->run([
            ['rotation' => 360],
            ['rotation' => 450],
            ['rotation' => -90],
            ['rotation' => -360],
        ]);

        $this->assertSame(0, $result[0]['rotation']);
        $this->assertSame(90, $result[1]['rotation']);
        $this->assertSame(270, $result[2]['rotation']);
        $this->assertSame(0, $result[3]['rotation']);
    }

    public function testTitleIsTrimmedAndCappedAt100Chars(): void
    {
        $result = $this->subject()->run([
            ['title' => '  padded  '],
            ['title' => str_repeat('a', 150)],
        ]);

        $this->assertSame('padded', $result[0]['title']);
        $this->assertSame(100, mb_strlen($result[1]['title']));
    }

    public function testDefaultsAreAppliedForMissingFields(): void
    {
        $result = $this->subject()->run([[]]);

        $this->assertSame(
            ['order' => 0, 'title' => '', 'x' => 50.0, 'y' => 50.0, 'rotation' => 0],
            $result[0]
        );
    }

    public function testOutputKeysAreReindexedSequentially(): void
    {
        $result = $this->subject()->run([
            5 => ['order' => 1],
            9 => ['order' => 2],
        ]);

        $this->assertSame([0, 1], array_keys($result));
    }
}
