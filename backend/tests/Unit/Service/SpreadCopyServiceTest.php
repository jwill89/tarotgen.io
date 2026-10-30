<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Service\SpreadCopyService;

/**
 * Copying a public spread into My Spreads: the name the copy gets, and nothing
 * made from a spread that isn't in the library.
 */
#[CoversClass(SpreadCopyService::class)]
final class SpreadCopyServiceTest extends TestCase
{
    public function testTheFirstCopyIsMarkedACopy(): void
    {
        $this->assertSame('Celtic Cross (copy)', SpreadCopyService::copyName('Celtic Cross', []));
        $this->assertSame('Celtic Cross (copy)', SpreadCopyService::copyName('  Celtic Cross ', ['celtic cross']));
    }

    public function testLaterCopiesAreNumberedPastTheNamesTaken(): void
    {
        $taken = ['celtic cross (copy)', 'celtic cross (copy 2)', 'celtic cross (copy 4)'];

        $this->assertSame('Celtic Cross (copy 3)', SpreadCopyService::copyName('Celtic Cross', $taken));
    }

    public function testALongNameIsShortenedToKeepTheSuffixWithinTheCap(): void
    {
        $name = str_repeat('ñ', 200);

        $first = SpreadCopyService::copyName($name, []);
        $this->assertSame(200, mb_strlen($first));
        $this->assertStringEndsWith(' (copy)', $first);

        $second = SpreadCopyService::copyName($name, [mb_strtolower($first)]);
        $this->assertSame(200, mb_strlen($second));
        $this->assertStringEndsWith(' (copy 2)', $second);
    }

    public function testNothingIsMadeFromASpreadThatIsNotInTheLibrary(): void
    {
        $spreads = $this->createStub(SpreadRepository::class);
        $spreads->method('get')->willReturn([]);
        $userSpreads = $this->createMock(UserSpreadRepository::class);
        $userSpreads->expects($this->never())->method('create');

        $this->assertNull(new SpreadCopyService($spreads, $userSpreads)->copyToAccount(9, 1));
    }
}
