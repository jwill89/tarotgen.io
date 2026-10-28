import { describe, it, expect, vi } from 'vitest'
import { useAccount } from '@/composables/useAccount'

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
