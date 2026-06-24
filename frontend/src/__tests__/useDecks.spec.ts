import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useDecks } from '@/composables/useDecks'

function fakeResponse(body: unknown, { ok = true } = {}): Response {
    return { ok, json: async () => body } as unknown as Response
}

const sampleDecks = [
    { deck_id: 1, name: 'Rider-Waite' },
    { deck_id: 2, name: 'Thoth' },
]

describe('useDecks', () => {
    beforeEach(() => {
        // Reset the module-level cache between tests.
        useDecks().decks.value = []
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

    it('refetches when force = true', async () => {
        const fetchMock = vi.fn().mockResolvedValue(fakeResponse(sampleDecks))
        vi.stubGlobal('fetch', fetchMock)

        const { fetchDecks } = useDecks()
        await fetchDecks()
        await fetchDecks(true)

        expect(fetchMock).toHaveBeenCalledTimes(2)
    })

    it('leaves decks empty on a non-ok response', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(null, { ok: false })))

        const { decks, fetchDecks } = useDecks()
        await fetchDecks()

        expect(decks.value).toEqual([])
    })

    it('fails silently on a network error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

        const { decks, fetchDecks } = useDecks()
        await expect(fetchDecks()).resolves.toBeUndefined()
        expect(decks.value).toEqual([])
    })
})
