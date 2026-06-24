import { ref, computed, type Ref } from 'vue'
import type { Spread, SpreadOption } from '@/types'
import { apiFetch } from './useApi'
import { useUserSpreads } from './useUserSpreads'
import { useFavoriteSpreads } from './useFavoriteSpreads'
import { useUser } from './useUser'

const spreads: Ref<Spread[]> = ref([])

export function useSpreads() {
    const { userSpreads, fetchUserSpreads } = useUserSpreads()
    const { fetchFavorites, isFavorite } = useFavoriteSpreads()
    const { isLoggedIn } = useUser()

    async function fetchSpreads(): Promise<void> {
        const data = await apiFetch<Spread[]>('/spread/')
        if (data) spreads.value = data
        // Also fetch user spreads and favorites if logged in.
        if (isLoggedIn.value) {
            await Promise.all([fetchUserSpreads(), fetchFavorites()])
        }
    }

    /** Combined list of public + personal spreads for the spread selector. */
    const spreadOptions = computed<SpreadOption[]>(() => {
        const publicOpts: SpreadOption[] = spreads.value.map(s => ({
            id: `public-${s.spread_id}`,
            spread_id: s.spread_id,
            name: s.name,
            description: s.description,
            card_count: s.card_count,
            positions: s.positions,
            type: 'public',
            isFavorite: isFavorite('public', s.spread_id),
        }))

        const userOpts: SpreadOption[] = userSpreads.value.map(s => ({
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
