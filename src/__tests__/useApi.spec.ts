import { describe, it, expect, beforeEach, vi } from 'vitest'

// useAdminApi() calls useRouter() from vue-router, which only works inside a
// component setup. Mock it so the composable can run standalone.
const { pushMock } = vi.hoisted(() => ({ pushMock: vi.fn() }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: pushMock }) }))

import { apiFetch, apiRequest, useAdminApi, readApiError } from '@/composables/useApi'
import { useUser } from '@/composables/useUser'

/** Build a minimal Response-like object for the fetch mock. */
function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
    return { ok, status, json: async () => body } as unknown as Response
}

describe('apiFetch', () => {
    beforeEach(() => {
        pushMock.mockClear()
    })

    it('prefixes /api and returns parsed JSON on success', async () => {
        const fetchMock = vi.fn().mockResolvedValue(fakeResponse([{ id: 1 }]))
        vi.stubGlobal('fetch', fetchMock)

        const result = await apiFetch<{ id: number }[]>('/deck/')

        expect(fetchMock).toHaveBeenCalledWith('/api/deck/', undefined)
        expect(result).toEqual([{ id: 1 }])
    })

    it('returns null on a non-ok response', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 500 })))
        expect(await apiFetch('/deck/')).toBeNull()
    })

    it('returns null when fetch rejects (network error)', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
        expect(await apiFetch('/deck/')).toBeNull()
    })
})

describe('apiRequest', () => {
    it('returns ok with parsed data and status on success', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse({ id: 1 }, { status: 201 })))

        const res = await apiRequest<{ id: number }>('/deck/')

        expect(res).toEqual({ ok: true, status: 201, data: { id: 1 } })
    })

    it('on an error status returns the server message, status, body, and networkError=false', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            fakeResponse({ error: 'Bad deck' }, { ok: false, status: 400 }),
        ))

        const res = await apiRequest('/deck/')

        expect(res.ok).toBe(false)
        if (!res.ok) {
            expect(res.status).toBe(400)
            expect(res.error).toBe('Bad deck')
            expect(res.networkError).toBe(false)
            expect(res.data).toEqual({ error: 'Bad deck' })
        }
    })

    it('uses the supplied fallback when the error body has no message', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse({}, { ok: false, status: 500 })))

        const res = await apiRequest('/deck/', undefined, 'Custom fallback.')

        expect(res.ok).toBe(false)
        if (!res.ok) expect(res.error).toBe('Custom fallback.')
    })

    it('flags transport failures with networkError=true and status 0', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

        const res = await apiRequest('/deck/')

        expect(res.ok).toBe(false)
        if (!res.ok) {
            expect(res.networkError).toBe(true)
            expect(res.status).toBe(0)
        }
    })
})

describe('readApiError', () => {
    it('extracts the server error message', async () => {
        const res = new Response(JSON.stringify({ error: 'Bad deck' }), { status: 400 })
        expect(await readApiError(res, 'fallback')).toBe('Bad deck')
    })

    it('falls back when there is no error field', async () => {
        const res = new Response(JSON.stringify({ ok: true }), { status: 500 })
        expect(await readApiError(res, 'fallback')).toBe('fallback')
    })

    it('falls back for a non-JSON body', async () => {
        const res = new Response('<html>oops</html>', { status: 500 })
        expect(await readApiError(res, 'fallback')).toBe('fallback')
    })

    it('falls back for a blank error string', async () => {
        const res = new Response(JSON.stringify({ error: '   ' }), { status: 400 })
        expect(await readApiError(res, 'fallback')).toBe('fallback')
    })
})

describe('useAdminApi', () => {
    beforeEach(() => {
        pushMock.mockClear()
        useUser().currentUser.value = { user_id: 1, is_admin: true } as never
    })

    it('prefixes /api/admin and returns JSON on success', async () => {
        const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ ok: true }))
        vi.stubGlobal('fetch', fetchMock)

        const result = await useAdminApi().get<{ ok: boolean }>('/decks')

        expect(fetchMock).toHaveBeenCalledWith('/api/admin/decks', expect.objectContaining({
            headers: { 'Content-Type': 'application/json' },
        }))
        expect(result).toEqual({ ok: true })
    })

    it('on 401 clears the user, redirects to login, and returns null', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 401 })))

        const { currentUser } = useUser()
        const result = await useAdminApi().get('/decks')

        expect(result).toBeNull()
        expect(currentUser.value).toBeNull()
        expect(pushMock).toHaveBeenCalledWith({ name: 'login' })
    })

    it('returns null on other error statuses without redirecting', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 404 })))

        const result = await useAdminApi().get('/decks')

        expect(result).toBeNull()
        expect(pushMock).not.toHaveBeenCalled()
    })

    it('sends POST with a JSON-encoded body', async () => {
        const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ created: true }))
        vi.stubGlobal('fetch', fetchMock)

        await useAdminApi().post('/decks', { name: 'New Deck' })

        expect(fetchMock).toHaveBeenCalledWith('/api/admin/decks', expect.objectContaining({
            method: 'POST',
            body: JSON.stringify({ name: 'New Deck' }),
        }))
    })

    it('sends DELETE requests', async () => {
        const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ success: true }))
        vi.stubGlobal('fetch', fetchMock)

        await useAdminApi().del('/decks/5')

        expect(fetchMock).toHaveBeenCalledWith('/api/admin/decks/5', expect.objectContaining({
            method: 'DELETE',
        }))
    })

    it('returns null when fetch rejects', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
        expect(await useAdminApi().get('/decks')).toBeNull()
    })
})
