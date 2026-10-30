<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\ReadingData;
use Tarot\Data\ReadingFilter;
use Tarot\Repository\ReadingRepository;
use Tarot\Structure\ReadingSpread;
use Tarot\Structure\ReadingTag;

/**
 * The reading repository hands the journal's queries and the retention purge
 * to the Data layer as given.
 */
#[CoversClass(ReadingRepository::class)]
final class ReadingRepositoryTest extends TestCase
{
    public function testThePurgeIsARealRunUnlessADryRunIsAskedFor(): void
    {
        $data = $this->createMock(ReadingData::class);
        $data->expects($this->exactly(2))
            ->method('purgeGuests')
            ->willReturnCallback(static fn(int $days, bool $dryRun): array => ['expired' => $dryRun ? 1 : 2, 'aged' => $days]);

        $repository = new ReadingRepository($data);

        $this->assertSame(['expired' => 2, 'aged' => 365], $repository->purgeGuests(365));
        $this->assertSame(['expired' => 1, 'aged' => 30], $repository->purgeGuests(30, true));
    }

    public function testTheFilterAndTheTagAndSpreadCountsReachTheDataLayer(): void
    {
        $filter = new ReadingFilter(tag: 'career');
        $tags = [new ReadingTag(['tag' => 'career', 'count' => 2])];
        $spreads = [new ReadingSpread(['name' => 'Celtic Cross', 'count' => 3])];

        $data = $this->createMock(ReadingData::class);
        $data->expects($this->once())->method('listByUser')->with(7, 25, 50, $filter)->willReturn([]);
        $data->expects($this->once())->method('countByUser')->with(7, $filter)->willReturn(4);
        $data->expects($this->once())->method('tagCounts')->with(7)->willReturn($tags);
        $data->expects($this->once())->method('spreadCounts')->with(7)->willReturn($spreads);

        $repository = new ReadingRepository($data);

        $this->assertSame([], $repository->listByUser(7, 25, 50, $filter));
        $this->assertSame(4, $repository->countByUser(7, $filter));
        $this->assertSame($tags, $repository->tagCounts(7));
        $this->assertSame($spreads, $repository->spreadCounts(7));
    }
}
