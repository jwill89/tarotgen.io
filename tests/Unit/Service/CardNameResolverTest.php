<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Repository\DeckSystemRepository;
use Tarot\Repository\SpecialCardRepository;
use Tarot\Service\CardNameResolver;
use Tarot\Structure\Deck;
use Tarot\Structure\DeckSystemCard;
use Tarot\Structure\SpecialCard;

#[CoversClass(CardNameResolver::class)]
final class CardNameResolverTest extends TestCase
{
    private function systemCard(int $id, string $name): DeckSystemCard
    {
        return new DeckSystemCard(['deck_system_id' => 1, 'card_id' => $id, 'name' => $name]);
    }

    private function special(int $deckId, int $id, string $name): SpecialCard
    {
        return new SpecialCard(['deck_id' => $deckId, 'card_id' => $id, 'name' => $name]);
    }

    public function testStandardCardsUseSystemName(): void
    {
        $deckSystems = $this->createMock(DeckSystemRepository::class);
        $deckSystems->expects($this->once())
            ->method('getCardsByIds')
            ->with(1, [1, 2])
            ->willReturn([$this->systemCard(1, 'The Fool'), $this->systemCard(2, 'The Magician')]);

        $special = $this->createMock(SpecialCardRepository::class);
        $special->expects($this->never())->method('getMultiple');

        $resolver = new CardNameResolver($deckSystems, $special);
        $names    = $resolver->resolve(new Deck(['deck_id' => 1, 'deck_system_id' => 1, 'system_total_cards' => 78]), [1, 2]);

        $this->assertSame([1 => 'The Fool', 2 => 'The Magician'], $names);
    }

    public function testCardsBeyondSystemTotalRouteToSpecialCards(): void
    {
        $deckSystems = $this->createMock(DeckSystemRepository::class);
        $deckSystems->expects($this->once())->method('getCardsByIds')->with(1, [5])->willReturn([$this->systemCard(5, 'Standard')]);

        $special = $this->createMock(SpecialCardRepository::class);
        $special->expects($this->once())
            ->method('getMultiple')
            ->with(2, [79])
            ->willReturn([$this->special(2, 79, 'Promo Card')]);

        $resolver = new CardNameResolver($deckSystems, $special);
        $names    = $resolver->resolve(new Deck(['deck_id' => 2, 'deck_system_id' => 1, 'system_total_cards' => 78]), [5, 79]);

        $this->assertSame([5 => 'Standard', 79 => 'Promo Card'], $names);
    }

    public function testDuplicateIdsAreDeduplicatedBeforeQuerying(): void
    {
        $deckSystems = $this->createMock(DeckSystemRepository::class);
        $deckSystems->expects($this->once())
            ->method('getCardsByIds')
            ->with(1, $this->callback(fn(array $ids) => array_values($ids) === [1]))
            ->willReturn([$this->systemCard(1, 'The Fool')]);

        $special = $this->createStub(SpecialCardRepository::class);

        $resolver = new CardNameResolver($deckSystems, $special);
        $names    = $resolver->resolve(new Deck(['deck_id' => 1, 'deck_system_id' => 1, 'system_total_cards' => 78]), [1, 1, 1]);

        $this->assertSame([1 => 'The Fool'], $names);
    }

    public function testEmptyInputQueriesNothing(): void
    {
        $deckSystems = $this->createMock(DeckSystemRepository::class);
        $deckSystems->expects($this->never())->method('getCardsByIds');

        $special = $this->createMock(SpecialCardRepository::class);
        $special->expects($this->never())->method('getMultiple');

        $resolver = new CardNameResolver($deckSystems, $special);

        $this->assertSame([], $resolver->resolve(new Deck(['deck_id' => 1, 'deck_system_id' => 1, 'system_total_cards' => 78]), []));
    }

    public function testUnknownIdsAreOmittedFromResult(): void
    {
        $deckSystems = $this->createStub(DeckSystemRepository::class);
        // Repo only returns id 1; id 999 is not found.
        $deckSystems->method('getCardsByIds')->willReturn([$this->systemCard(1, 'The Fool')]);

        $special = $this->createStub(SpecialCardRepository::class);

        $resolver = new CardNameResolver($deckSystems, $special);
        $names    = $resolver->resolve(new Deck(['deck_id' => 1, 'deck_system_id' => 1, 'system_total_cards' => 78]), [1, 999]);

        $this->assertSame([1 => 'The Fool'], $names);
        $this->assertArrayNotHasKey(999, $names);
    }
}
