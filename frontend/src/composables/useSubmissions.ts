import { endpoints } from '@/api/endpoints'
import { apiRequest, sendJson, type ApiResult } from './apiClient'
import type { DeckSystem, DeckSystemCard } from '@/types'

export interface DeckSubmission {
  name: string
  artist: string
  purchase_url: string
  deck_system_id: number
  additional_cards: number
}

export interface DeckSystemSubmission {
  name: string
  short_name: string
  total_cards: number
  cards: DeckSystemCard[]
}

export interface SpreadSubmission {
  name: string
  description: string
  card_count: number
  positions: unknown[]
}

/**
 * What visitors propose for the site: decks, deck systems and spreads, each queued
 * for an admin to approve. The server's own message comes back in `error` (a rate
 * limit included), so pages show it as is.
 */
export function useSubmissions() {
  function submitDeck(deck: DeckSubmission): Promise<ApiResult<unknown>> {
    return apiRequest(
      endpoints.decks.list,
      sendJson('POST', deck),
      'Failed to submit deck. Please try again.',
    )
  }

  function submitDeckSystem(system: DeckSystemSubmission): Promise<ApiResult<DeckSystem>> {
    return apiRequest(
      endpoints.deckSystems.list,
      sendJson('POST', system),
      'Failed to submit deck system. Please try again.',
    )
  }

  function submitSpread(spread: SpreadSubmission): Promise<ApiResult<unknown>> {
    return apiRequest(
      endpoints.spreads.list,
      sendJson('POST', spread),
      'Failed to submit the spread. Please try again.',
    )
  }

  return { submitDeck, submitDeckSystem, submitSpread }
}
