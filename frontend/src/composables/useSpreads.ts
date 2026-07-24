import { ref, computed, type Ref } from 'vue'
import type { Spread, SpreadOption } from '@/types'
import { apiFetch } from './useApi'
import { endpoints } from '@/api/endpoints'
import { useUserSpreads } from './useUserSpreads'
import { useFavoriteSpreads } from './useFavoriteSpreads'
import { useUser } from './useUser'

const spreads: Ref<Spread[]> = ref([])

export function useSpreads() {
  const { userSpreads, fetchUserSpreads } = useUserSpreads()
  const { fetchFavorites, isFavorite } = useFavoriteSpreads()
  const { isLoggedIn } = useUser()

  async function fetchSpreads(): Promise<void> {
    // Fire the public list, personal spreads, and favorites concurrently. The
    // favorites/user-spreads calls don't depend on the public list (favorites are
    // reconciled by id in spreadOptions), so awaiting the public list first only
    // added a serial round trip to every spread-selector page load.
    const [data] = await Promise.all([
      apiFetch<Spread[]>(endpoints.spreads.list),
      isLoggedIn.value ? fetchUserSpreads() : Promise.resolve(),
      isLoggedIn.value ? fetchFavorites() : Promise.resolve(),
    ])
    if (data) spreads.value = data
  }

  /** Combined list of public + personal spreads for the spread selector. */
  const spreadOptions = computed<SpreadOption[]>(() => {
    const publicOpts: SpreadOption[] = spreads.value.map((s) => ({
      id: `public-${s.spread_id}`,
      spread_id: s.spread_id,
      name: s.name,
      description: s.description,
      card_count: s.card_count,
      positions: s.positions,
      type: 'public',
      isFavorite: isFavorite('public', s.spread_id),
    }))

    const userOpts: SpreadOption[] = userSpreads.value.map((s) => ({
      id: `user-${s.user_spread_id}`,
      user_spread_id: s.user_spread_id,
      name: s.name,
      description: s.description,
      card_count: s.card_count,
      positions: s.positions,
      type: 'personal',
      isFavorite: isFavorite('personal', s.user_spread_id),
    }))

    return [...userOpts, ...publicOpts]
  })

  return { spreads, spreadOptions, fetchSpreads }
}
