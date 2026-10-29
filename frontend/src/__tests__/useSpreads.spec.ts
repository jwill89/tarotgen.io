import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useSpreads } from '@/composables/useSpreads'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useFavoriteSpreads } from '@/composables/useFavoriteSpreads'
import { useToasts } from '@/composables/useToasts'
import { stubApi, signIn, testUser } from './helpers/app'
import type { Spread, SpreadPosition, UserSpread } from '@/types'

const positions: SpreadPosition[] = [
  { order: 1, title: 'Past', x: 30, y: 50, rotation: 0 },
  { order: 2, title: 'Future', x: 70, y: 50, rotation: 0 },
]

const publicSpread = (spread_id: number, name: string): Spread => ({
  spread_id,
  name,
  description: `${name} spread`,
  card_count: 2,
  positions,
})

const personalSpread = (user_spread_id: number, name: string): UserSpread => ({
  user_spread_id,
  user_id: 7,
  name,
  description: '',
  card_count: 2,
  positions,
  created_at: '2026-09-01 10:00:00',
  updated_at: '2026-09-01 10:00:00',
})

describe('useSpreads', () => {
  beforeEach(() => {
    signIn(null)
    useSpreads().spreads.value = []
    useUserSpreads().userSpreads.value = []
    useFavoriteSpreads().favorites.value = []
    useToasts().toasts.value = []
  })

  it('offers only the public spreads to a signed-out visitor', async () => {
    // Any account call would be unexpected and fail the test.
    stubApi({ 'GET /spreads': { body: [publicSpread(3, 'Two Paths')] } })
    const { fetchSpreads, spreadOptions } = useSpreads()

    await fetchSpreads()

    expect(spreadOptions.value).toEqual([
      {
        id: 'public-3',
        spread_id: 3,
        name: 'Two Paths',
        description: 'Two Paths spread',
        card_count: 2,
        positions,
        type: 'public',
        isFavorite: false,
      },
    ])
  })

  it("lists a signed-in user's own spreads first and marks their favourites", async () => {
    signIn(testUser())
    stubApi({
      'GET /spreads': { body: [publicSpread(3, 'Two Paths'), publicSpread(4, 'Crossroads')] },
      'GET /account/spreads': { body: [personalSpread(12, 'My Morning')] },
      'GET /account/favorites': {
        body: [
          { spread_type: 'public', spread_id: 4 },
          { spread_type: 'personal', spread_id: 12 },
        ],
      },
    })
    const { fetchSpreads, spreadOptions } = useSpreads()

    await fetchSpreads()

    expect(
      spreadOptions.value.map(({ id, type, isFavorite }) => ({ id, type, isFavorite })),
    ).toEqual([
      { id: 'user-12', type: 'personal', isFavorite: true },
      { id: 'public-3', type: 'public', isFavorite: false },
      { id: 'public-4', type: 'public', isFavorite: true },
    ])
    expect(spreadOptions.value[0]?.user_spread_id).toBe(12)
  })

  it("says why the picker is empty when the spreads can't load", async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    stubApi({ 'GET /spreads': { status: 503, body: { error: 'Down for maintenance.' } } })
    const { fetchSpreads, spreads } = useSpreads()
    spreads.value = [publicSpread(3, 'Two Paths')]

    await fetchSpreads()

    expect(useToasts().toasts.value.map((t) => t.message)).toEqual([
      "Couldn't load the spreads. Down for maintenance.",
    ])
    expect(spreads.value).toHaveLength(1)
  })
})
