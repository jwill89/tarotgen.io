import { ref, type Ref } from 'vue'
import type { Deck } from '@/types'

const decks: Ref<Deck[]> = ref([])
const deckLookup: Ref<Record<number, Deck>> = ref({})

export function useDecks() {
    // Decks rarely change within a session and are shared module-wide, so by
    // default we fetch them only once. Pass force = true to refresh on demand.
    async function fetchDecks(force = false): Promise<void> {
        if (!force && decks.value.length > 0) {
            return
        }

        try {
            const res = await fetch('/api/deck/')
            if (!res.ok) {
                return
            }
            const data: Deck[] = await res.json()

            const lookup: Record<number, Deck> = {}
            data.forEach(deck => {
                lookup[deck.deck_id] = deck
            })
            deckLookup.value = lookup

            // Selects everywhere show decks alphabetically by name.
            decks.value = [...data].sort((a, b) => a.name.localeCompare(b.name))
        } catch {
            // Silently fail on deck load
        }
    }

    return { decks, deckLookup, fetchDecks }
}
