import { ref, type Ref } from 'vue'
import { apiFetch } from './useApi'
import { endpoints } from '@/api/endpoints'

const favoriteDecks: Ref<number[]> = ref([])
// Dedupe concurrent callers into a single in-flight request (see useFavoriteSpreads).
let inFlight: Promise<void> | null = null

export function useFavoriteDecks() {
  function fetchFavoriteDecks(): Promise<void> {
    if (inFlight) return inFlight
    inFlight = (async () => {
      const data = await apiFetch<number[]>(endpoints.account.favoriteDecks)
      if (data) favoriteDecks.value = data
    })().finally(() => {
      inFlight = null
    })
    return inFlight
  }

  function isFavorite(deckId: number): boolean {
    return favoriteDecks.value.includes(deckId)
  }

  async function addFavorite(deckId: number): Promise<boolean> {
    const res = await fetch('/api' + endpoints.account.favoriteDecks, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ deck_id: deckId }),
    })
    if (!res.ok) return false
    favoriteDecks.value = [...favoriteDecks.value, deckId]
    return true
  }

  async function removeFavorite(deckId: number): Promise<boolean> {
    const res = await fetch('/api' + endpoints.account.favoriteDeckById(deckId), {
      method: 'DELETE',
    })
    if (!res.ok) return false
    favoriteDecks.value = favoriteDecks.value.filter((id) => id !== deckId)
    return true
  }

  async function toggleFavorite(deckId: number): Promise<boolean> {
    if (isFavorite(deckId)) {
      return removeFavorite(deckId)
    } else {
      return addFavorite(deckId)
    }
  }

  return {
    favoriteDecks,
    fetchFavoriteDecks,
    isFavorite,
    addFavorite,
    removeFavorite,
    toggleFavorite,
  }
}
