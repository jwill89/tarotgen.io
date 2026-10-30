import { describe, it, expect } from 'vitest'
import { useCardFrequency } from '@/composables/useCardFrequency'
import { stubApi } from './helpers/app'

describe('useCardFrequency', () => {
  it('asks for every deck and all time unless told otherwise', async () => {
    const api = stubApi({
      'GET /account/card-frequency': {
        body: { readings: 0, deck_id: null, days: null, cards: [] },
      },
    })
    const { fetchCardFrequency } = useCardFrequency()

    await fetchCardFrequency({ deckId: null, days: null })
    await fetchCardFrequency({ deckId: 4, days: null })
    await fetchCardFrequency({ deckId: null, days: 365 })
    await fetchCardFrequency({ deckId: 4, days: 30 })

    expect(api.mock.calls.map(([path]) => path)).toEqual([
      '/api/account/card-frequency',
      '/api/account/card-frequency?deck_id=4',
      '/api/account/card-frequency?days=365',
      '/api/account/card-frequency?deck_id=4&days=30',
    ])
  })
})
