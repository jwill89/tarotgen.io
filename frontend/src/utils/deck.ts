import type { Deck } from '@/types'

/**
 * Label for a deck <option>, with the deck system short name and extra card count.
 */
export function deckLabel(deck: Deck): string {
  const base = `${deck.name} — art by ${deck.artist}`

  const tags: string[] = []
  if (deck.system_short_name) {
    tags.push(deck.system_short_name)
  }
  if (deck.additional_cards > 0) {
    tags.push(`+${deck.additional_cards} extra card${deck.additional_cards === 1 ? '' : 's'}`)
  }

  return tags.length ? `${base} (${tags.join('; ')})` : base
}

/**
 * The deck to select by default: a still-available remembered choice if given,
 * otherwise Rider-Waite (deck 1 / name match), otherwise the first deck.
 */
export function defaultDeckId(decks: Deck[], rememberedId: number | null = null): number | null {
  if (decks.length === 0) return null
  if (rememberedId !== null && decks.some((d) => d.deck_id === rememberedId)) {
    return rememberedId
  }
  const riderWaite =
    decks.find((d) => d.deck_id === 1) ?? decks.find((d) => /rider[-\s]?waite/i.test(d.name))
  return (riderWaite ?? decks[0]).deck_id
}
