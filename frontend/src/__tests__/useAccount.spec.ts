import { describe, it, expect, vi } from 'vitest'
import { useAccount } from '@/composables/useAccount'
import { useUser } from '@/composables/useUser'
import type { User } from '@/types'

function pageResponse(body: unknown, total: string | null, { ok = true, status = 200 } = {}) {
  return {
    ok,
    status,
    json: async () => body,
    headers: new Headers(total === null ? {} : { 'X-Total-Count': total }),
  } as unknown as Response
}

describe('useAccount.listReadingsPage', () => {
  it('asks for one page and reads the total from X-Total-Count', async () => {
    const fetchMock = vi.fn().mockResolvedValue(pageResponse([{ reading_id: 'a' }], '60'))
    vi.stubGlobal('fetch', fetchMock)

    const res = await useAccount().listReadingsPage(3, 25)

    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/account/readings?limit=25&offset=50')
    expect(res).toEqual({ ok: true, readings: [{ reading_id: 'a' }], total: 60 })
  })

  it('still pages sensibly if a proxy strips the header', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(pageResponse([{}, {}], null)))

    const res = await useAccount().listReadingsPage(2, 25)

    expect(res.ok && res.total).toBe(27) // the 25 before this page + the 2 on it
  })

  it('reports a failure instead of returning an empty list', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const res = await useAccount().listReadingsPage(1, 25)

    expect(res.ok).toBe(false)
    expect(!res.ok && res.error).toMatch(/Network error/)
  })
})

function jsonResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

describe('useAccount changes', () => {
  it('PATCHes reading settings and returns the updated reading', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ reading_id: 'a', hide_user: true }))
    vi.stubGlobal('fetch', fetchMock)

    const res = await useAccount().updateReading('a', { hide_user: true })

    expect(fetchMock).toHaveBeenCalledWith('/api/account/readings/a', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ hide_user: true }),
    })
    expect(res).toEqual({ ok: true, reading: { reading_id: 'a', hide_user: true } })
  })

  it('signs out locally once the account is gone', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(null, { status: 204 })))
    useUser().currentUser.value = { user_id: 1 } as User

    expect(await useAccount().deleteAccount('pw')).toEqual({ ok: true })
    expect(useUser().currentUser.value).toBeNull()
  })

  it("keeps the user signed in and shows the server's reason when deletion is refused", async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse({ error: 'Incorrect password.' }, { ok: false, status: 403 }),
        ),
    )
    useUser().currentUser.value = { user_id: 1 } as User

    expect(await useAccount().deleteAccount('wrong')).toEqual({
      ok: false,
      error: 'Incorrect password.',
    })
    expect(useUser().currentUser.value?.user_id).toBe(1)
  })

  it("explains why Google can't be unlinked", async () => {
    const reason =
      'Cannot unlink Google — your account has no password set. Please set a password first.'
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse({ error: reason }, { ok: false, status: 400 })),
    )

    expect(await useAccount().unlinkGoogle()).toEqual({ ok: false, error: reason })
  })
})
