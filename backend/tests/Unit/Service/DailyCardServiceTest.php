<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tarot\Exception\DailyCardTakenException;
use Tarot\Repository\DailyCardRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Service\DailyCardService;
use Tarot\Service\ReadingService;
use Tarot\Structure\DailyCard;
use Tarot\Structure\Reading;
use Tarot\Utility\Clock;

/**
 * The one path the HTTP tests can't reach: two draws for the same day racing,
 * where the second has already made its reading when the first one's link wins.
 */
#[CoversClass(DailyCardService::class)]
#[CoversClass(DailyCardTakenException::class)]
final class DailyCardServiceTest extends TestCase
{
    /** @return iterable<string,array{?string,string}> */
    public static function winners(): iterable
    {
        yield 'its reading' => ['winner0001', 'You have already drawn your daily card for this day.'];
        // The winning draw's reading was deleted before this one looked again.
        yield 'its reading deleted' => [null, 'You have already drawn your daily card for this day, and its reading was deleted.'];
    }

    #[DataProvider('winners')]
    public function testADrawThatLosesARaceLeavesNoStrayReading(?string $winnerReading, string $message): void
    {
        $today = Clock::today();
        $winner = new DailyCard(['day' => $today, 'reading_id' => $winnerReading]);

        $dailyCards = $this->createStub(DailyCardRepository::class);
        // Nothing yet when this draw looks; the other draw's card once it has lost.
        $dailyCards->method('forDay')->willReturnOnConsecutiveCalls(null, $winner);
        $dailyCards->method('link')->willReturn(false);

        $ours = new Reading();
        $ours->setReadingId('loser00002');
        $readingService = $this->createStub(ReadingService::class);
        $readingService->method('generate')->willReturn($ours);

        $readings = $this->createMock(ReadingRepository::class);
        $readings->expects($this->once())->method('deleteOwned')->with('loser00002', 7)->willReturn(true);

        $service = new DailyCardService($dailyCards, $readingService, $readings);

        try {
            $service->draw(7, ['day' => $today, 'deck_id' => 1]);
            $this->fail('The losing draw was accepted.');
        } catch (DailyCardTakenException $e) {
            $this->assertSame($winnerReading, $e->readingId);
            $this->assertSame($message, $e->getMessage());
        }
    }
}
