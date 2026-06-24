import { ref, type Ref } from 'vue'
import { apiFetch } from './useApi'

const favoriteDecks: Ref<number[]> = ref([])

export function useFavoriteDecks() {
    async function fetchFavoriteDecks(): Promise<void> {
        const data = await apiFetch<number[]>('/account/favorite-decks/')
        if (data) favoriteDecks.value = data
    }

    function isFavorite(deckId: number): boolean {
        return favoriteDecks.value.includes(deckId)
    }

    async function addFavorite(deckId: number): Promise<boolean> {
        const res = await fetch('/api/account/favorite-decks/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ deck_id: deckId }),
        })
        if (!res.ok) return false
        favoriteDecks.value = [...favoriteDecks.value, deckId]
        return true
    }

    async function removeFavorite(deckId: number): Promise<boolean> {
        const res = await fetch('/api/account/favorite-decks/', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ deck_id: deckId }),
        })
        if (!res.ok) return false
        favoriteDecks.value = favoriteDecks.value.filter(id => id !== deckId)
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

