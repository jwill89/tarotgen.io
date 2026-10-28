import { describe, it, expect } from 'vitest'
import { cardImageUrls, cardBackImageUrls } from '@/utils/cardImages'

describe('cardImageUrls', () => {
  it('builds the display, master and thumbnail paths for a card', () => {
    expect(cardImageUrls(3, 49)).toEqual({
      display: '/assets/decks/3/Card_0049.webp',
      png: '/assets/decks/3/Card_0049.png',
      thumb: '/assets/decks/3/thumbs/Card_0049.webp',
    })
  })

  it('pads card ids to four digits to match the scan file names', () => {
    expect(cardImageUrls(1, 1).png).toBe('/assets/decks/1/Card_0001.png')
    expect(cardImageUrls(1, 1234).png).toBe('/assets/decks/1/Card_1234.png')
  })
})

describe('cardBackImageUrls', () => {
  it('uses the Card_Back file name in the same layout', () => {
    expect(cardBackImageUrls(12)).toEqual({
      display: '/assets/decks/12/Card_Back.webp',
      png: '/assets/decks/12/Card_Back.png',
      thumb: '/assets/decks/12/thumbs/Card_Back.webp',
    })
  })
})
