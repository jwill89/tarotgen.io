import { ref, type Ref } from 'vue'
import { apiFetch } from './useApi'
import { endpoints } from '@/api/endpoints'

export interface FavoriteEntry {
  spread_type: 'public' | 'personal'
  spread_id: number
}

const favorites: Ref<FavoriteEntry[]> = ref([])
// Dedupe concurrent callers: a single page load mounts several components that
// each call fetchFavorites — collapse them into one in-flight request instead of
// a burst of identical authenticated calls (each of which the server serializes).
let inFlight: Promise<void> | null = null

export function useFavoriteSpreads() {
  function fetchFavorites(): Promise<void> {
    if (inFlight) return inFlight
    inFlight = (async () => {
      const data = await apiFetch<FavoriteEntry[]>(endpoints.account.favorites)
      if (data) favorites.value = data
    })().finally(() => {
      inFlight = null
    })
    return inFlight
  }

  function isFavorite(spreadType: 'public' | 'personal', spreadId: number): boolean {
    return favorites.value.some((f) => f.spread_type === spreadType && f.spread_id === spreadId)
  }

  async function addFavorite(
    spreadType: 'public' | 'personal',
    spreadId: number,
  ): Promise<boolean> {
    const res = await fetch('/api' + endpoints.account.favorites, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ spread_type: spreadType, spread_id: spreadId }),
    })
    if (!res.ok) return false
    favorites.value = [...favorites.value, { spread_type: spreadType, spread_id: spreadId }]
    return true
  }

  async function removeFavorite(
    spreadType: 'public' | 'personal',
    spreadId: number,
  ): Promise<boolean> {
    const res = await fetch('/api' + endpoints.account.favoriteById(spreadType, spreadId), {
      method: 'DELETE',
    })
    if (!res.ok) return false
    favorites.value = favorites.value.filter(
      (f) => !(f.spread_type === spreadType && f.spread_id === spreadId),
    )
    return true
  }

  async function toggleFavorite(
    spreadType: 'public' | 'personal',
    spreadId: number,
  ): Promise<boolean> {
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
