import { describe, it, expect } from 'vitest'
import { cardLabel, defaultDeckId } from '@/utils/deck'
import type { Deck } from '@/types'

/** Minimal Deck stub carrying just the fields defaultDeckId reads. */
const deck = (deck_id: number, name: string): Deck => ({ deck_id, name }) as Deck

describe('cardLabel', () => {
  it("uses the card's name", () => {
    expect(cardLabel({ card_id: 1, reversed: false, card_name: 'The Magician' })).toBe(
      'The Magician',
    )
  })

  it('numbers a card the deck has no name for', () => {
    expect(cardLabel({ card_id: 79, reversed: true })).toBe('Card #79')
  })
})

describe('defaultDeckId', () => {
  const decks = [deck(4, 'Thoth'), deck(1, 'Rider-Waite'), deck(7, 'Marseille')]

  it('has nothing to pick from an empty list', () => {
    expect(defaultDeckId([], 4)).toBeNull()
  })

  it('keeps a remembered deck that is still available', () => {
    expect(defaultDeckId(decks, 7)).toBe(7)
  })

  it('falls back to Rider-Waite (deck 1) when the remembered deck is gone', () => {
    expect(defaultDeckId(decks, 99)).toBe(1)
    expect(defaultDeckId(decks)).toBe(1)
  })

  it('finds Rider-Waite by name when it is not deck 1', () => {
    expect(defaultDeckId([deck(4, 'Thoth'), deck(9, 'Rider Waite Smith')])).toBe(9)
  })

  it('otherwise takes the first deck', () => {
    expect(defaultDeckId([deck(4, 'Thoth'), deck(7, 'Marseille')])).toBe(4)
  })
})
