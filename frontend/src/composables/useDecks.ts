import { ref, type Ref } from 'vue'
import { endpoints } from '@/api/endpoints'
import { apiRequest } from './apiClient'
import { useToasts } from './useToasts'
import type { Deck } from '@/types'

const decks: Ref<Deck[]> = ref([])
const deckLookup: Ref<Record<number, Deck>> = ref({})
// Several components on one page ask for decks at mount; share one request.
let inFlight: Promise<void> | null = null

export function useDecks() {
  // Decks rarely change within a session and are shared module-wide, so by
  // default we fetch them only once. Pass force = true to refresh on demand.
  function fetchDecks(force = false): Promise<void> {
    if (!force && decks.value.length > 0) return Promise.resolve()
    if (inFlight) return inFlight

    inFlight = (async () => {
      const res = await apiRequest<Deck[]>(endpoints.decks.list)
      if (!res.ok) {
        // An empty deck picker reads as "there are no decks" — say what
        // happened instead. The next call (e.g. a reload) tries again.
        useToasts().error(`Couldn't load the decks. ${res.error}`, { detail: res })
        return
      }

      const lookup: Record<number, Deck> = {}
      res.data.forEach((deck) => {
        lookup[deck.deck_id] = deck
      })
      deckLookup.value = lookup

      // Selects everywhere show decks alphabetically by name.
      decks.value = [...res.data].sort((a, b) => a.name.localeCompare(b.name))
    })().finally(() => {
      inFlight = null
    })
    return inFlight
  }

  return { decks, deckLookup, fetchDecks }
}
