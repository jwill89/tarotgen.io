import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useFavoriteDecks } from '@/composables/useFavoriteDecks'
import { useToasts } from '@/composables/useToasts'

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

describe('useFavoriteDecks.toggleFavorite', () => {
  beforeEach(() => {
    useFavoriteDecks().favoriteDecks.value = []
    useToasts().toasts.value = []
  })

  it('adds a favourite on success', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(null, { status: 201 })))

    const { favoriteDecks, toggleFavorite } = useFavoriteDecks()

    expect(await toggleFavorite(3)).toBe(true)
    expect(favoriteDecks.value).toEqual([3])
    expect(useToasts().toasts.value).toEqual([])
  })

  it('keeps the star as it was and says why when the server refuses', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse({ error: 'x' }, { ok: false, status: 500 })),
    )

    const { favoriteDecks, toggleFavorite } = useFavoriteDecks()

    expect(await toggleFavorite(3)).toBe(false)
    expect(favoriteDecks.value).toEqual([])
    expect(useToasts().toasts.value[0]?.message).toMatch(/Couldn't update your favourite decks/)
  })

  it('does not reject on a network error (callers fire and forget)', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const { toggleFavorite } = useFavoriteDecks()

    await expect(toggleFavorite(3)).resolves.toBe(false)
    expect(useToasts().toasts.value).toHaveLength(1)
  })
})
