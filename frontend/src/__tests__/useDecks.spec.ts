import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useDecks } from '@/composables/useDecks'
import { useToasts } from '@/composables/useToasts'

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

const sampleDecks = [
  { deck_id: 1, name: 'Rider-Waite' },
  { deck_id: 2, name: 'Thoth' },
]

describe('useDecks', () => {
  beforeEach(() => {
    // Reset the module-level cache and the shared toast queue between tests.
    useDecks().decks.value = []
    useToasts().toasts.value = []
  })

  it('fetches and populates decks and the id lookup', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(sampleDecks)))

    const { decks, deckLookup, fetchDecks } = useDecks()
    await fetchDecks()

    expect(decks.value).toEqual(sampleDecks)
    expect(deckLookup.value[2]).toEqual({ deck_id: 2, name: 'Thoth' })
  })

  it('does not refetch when decks are already loaded', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse(sampleDecks))
    vi.stubGlobal('fetch', fetchMock)

    const { fetchDecks } = useDecks()
    await fetchDecks()
    await fetchDecks() // cached: should be a no-op

    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('shares one request between concurrent callers', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse(sampleDecks))
    vi.stubGlobal('fetch', fetchMock)

    const { fetchDecks } = useDecks()
    await Promise.all([fetchDecks(), fetchDecks(), fetchDecks()])

    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('refetches when force = true', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse(sampleDecks))
    vi.stubGlobal('fetch', fetchMock)

    const { fetchDecks } = useDecks()
    await fetchDecks()
    await fetchDecks(true)

    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('says so in a toast, rather than showing an empty picker, when the load fails', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 500 })),
    )

    const { decks, fetchDecks } = useDecks()
    await fetchDecks()

    expect(decks.value).toEqual([])
    const [toast] = useToasts().toasts.value
    expect(toast?.type).toBe('error')
    expect(toast?.message).toMatch(/Couldn't load the decks/)
  })

  it('reports a network error the same way, without throwing', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const { decks, fetchDecks } = useDecks()
    await expect(fetchDecks()).resolves.toBeUndefined()

    expect(decks.value).toEqual([])
    expect(useToasts().toasts.value[0]?.message).toMatch(/Network error/)
  })

  it('tries again on the next call after a failure', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValue(fakeResponse(sampleDecks))
    vi.stubGlobal('fetch', fetchMock)

    const { decks, fetchDecks } = useDecks()
    await fetchDecks()
    await fetchDecks()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(decks.value).toEqual(sampleDecks)
  })
})
