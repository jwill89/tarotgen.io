import { ref, type Ref } from 'vue'
import { apiFetch } from './useApi'
import { endpoints } from '@/api/endpoints'

export interface FavoriteEntry {
    spread_type: 'public' | 'personal'
    spread_id: number
}

const favorites: Ref<FavoriteEntry[]> = ref([])

export function useFavoriteSpreads() {
    async function fetchFavorites(): Promise<void> {
        const data = await apiFetch<FavoriteEntry[]>(endpoints.account.favorites)
        if (data) favorites.value = data
    }

    function isFavorite(spreadType: 'public' | 'personal', spreadId: number): boolean {
        return favorites.value.some(f => f.spread_type === spreadType && f.spread_id === spreadId)
    }

    async function addFavorite(spreadType: 'public' | 'personal', spreadId: number): Promise<boolean> {
        const res = await fetch('/api' + endpoints.account.favorites, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ spread_type: spreadType, spread_id: spreadId }),
        })
        if (!res.ok) return false
        favorites.value = [...favorites.value, { spread_type: spreadType, spread_id: spreadId }]
        return true
    }

    async function removeFavorite(spreadType: 'public' | 'personal', spreadId: number): Promise<boolean> {
        const res = await fetch('/api' + endpoints.account.favoriteById(spreadType, spreadId), {
            method: 'DELETE',
        })
        if (!res.ok) return false
        favorites.value = favorites.value.filter(
            f => !(f.spread_type === spreadType && f.spread_id === spreadId)
        )
        return true
    }

    async function toggleFavorite(spreadType: 'public' | 'personal', spreadId: number): Promise<boolean> {
        if (isFavorite(spreadType, spreadId)) {
            return removeFavorite(spreadType, spreadId)
        } else {
            return addFavorite(spreadType, spreadId)
        }
    }

    return {
        favorites,
        fetchFavorites,
        isFavorite,
        addFavorite,
        removeFavorite,
        toggleFavorite,
    }
}

