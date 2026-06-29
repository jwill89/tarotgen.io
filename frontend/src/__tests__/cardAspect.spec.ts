import { describe, it, expect } from 'vitest'
import { cardAspectRatio, cardAspectStyle, DEFAULT_CARD_ASPECT } from '@/utils/cardAspect'
import type { Deck } from '@/types'

/** Minimal Deck stub carrying just the aspect fields the helpers read. */
const deck = (w: number, h: number): Deck => ({ card_aspect_w: w, card_aspect_h: h } as Deck)

describe('cardAspectRatio', () => {
    it('formats a deck\'s aspect as a CSS ratio', () => {
        expect(cardAspectRatio(deck(70.5, 151.5))).toBe('70.5 / 151.5')
        expect(cardAspectRatio(deck(5, 8.6))).toBe('5 / 8.6')
    })

    it('falls back to the default when no deck is given', () => {
        expect(cardAspectRatio(null)).toBe(DEFAULT_CARD_ASPECT)
        expect(cardAspectRatio(undefined)).toBe(DEFAULT_CARD_ASPECT)
    })

    it('falls back to the default for non-positive dimensions (never emits "/ 0")', () => {
        expect(cardAspectRatio(deck(0, 8.6))).toBe(DEFAULT_CARD_ASPECT)
        expect(cardAspectRatio(deck(5, 0))).toBe(DEFAULT_CARD_ASPECT)
        expect(cardAspectRatio(deck(-5, -8.6))).toBe(DEFAULT_CARD_ASPECT)
    })
})

describe('cardAspectStyle', () => {
    it('exposes the ratio as the --card-aspect custom property', () => {
        expect(cardAspectStyle(deck(70.5, 151.5))).toEqual({ '--card-aspect': '70.5 / 151.5' })
    })

    it('uses the default custom-property value when no deck is given', () => {
        expect(cardAspectStyle(null)).toEqual({ '--card-aspect': DEFAULT_CARD_ASPECT })
    })
})
