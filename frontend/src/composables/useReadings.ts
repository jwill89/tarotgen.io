import { endpoints } from '@/api/endpoints'
import { apiRequest, sendForm, sendJson, type ApiResult } from './apiClient'
import type { AccountReading, LockedReading, Reading } from '@/types'

/** A free-draw card's place on the canvas, as the placement editor saves it. */
export interface PlacedPosition {
  title: string
  x: number
  y: number
  rotation: number
}

/**
 * The reading endpoints. Every call resolves to an {@link ApiResult}, so a page
 * can tell "not found" from "offline" and show the server's own message.
 */
export function useReadings() {
  const path = (id: string) => encodeURIComponent(id)

  /** Draw a new reading from the New Reading form's fields. */
  function generate(fields: URLSearchParams): Promise<ApiResult<Reading>> {
    return apiRequest(
      endpoints.readings.generate,
      sendForm(fields),
      'There was an error generating your reading.',
    )
  }

  /** Save a reading made of hand-picked cards. Answers with the stored row, not a Reading. */
  function createCustom(payload: Record<string, unknown>): Promise<ApiResult<AccountReading>> {
    return apiRequest(
      endpoints.readings.create,
      sendJson('POST', payload),
      'There was an error saving your reading.',
    )
  }

  /** A reading, or the locked stub when it needs a password this visitor hasn't given. */
  function get(id: string): Promise<ApiResult<Reading | LockedReading>> {
    return apiRequest(endpoints.readings.byId(path(id)))
  }

  function unlock(id: string, password: string): Promise<ApiResult<Reading>> {
    return apiRequest(
      endpoints.readings.unlock(path(id)),
      sendJson('POST', { password }),
      'Incorrect password.',
    )
  }

  /** Draw more cards into the owner's reading. */
  function drawMore(id: string, fields: URLSearchParams): Promise<ApiResult<Reading>> {
    return apiRequest(
      endpoints.readings.draw(path(id)),
      sendForm(fields),
      'Could not draw more cards.',
    )
  }

  /** Lock the reading against further draws, for good. */
  function finalize(id: string): Promise<ApiResult<Reading>> {
    return apiRequest(
      endpoints.readings.finalize(path(id)),
      { method: 'POST' },
      'Could not finalize the reading.',
    )
  }

  /** Save where a free draw's cards sit, as a spread named `spreadName`. */
  function savePlacement(
    id: string,
    positions: PlacedPosition[],
    spreadName: string,
  ): Promise<ApiResult<unknown>> {
    return apiRequest(
      endpoints.readings.placement(path(id)),
      sendJson('PUT', { positions, spread_name: spreadName }),
      'Failed to save placement.',
    )
  }

  return { generate, createCustom, get, unlock, drawMore, finalize, savePlacement }
}
