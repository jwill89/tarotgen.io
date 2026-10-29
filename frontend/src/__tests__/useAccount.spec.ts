import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useAccount } from '@/composables/useAccount'
import { useUser } from '@/composables/useUser'
import type { User } from '@/types'
import { stubApi, signIn, testUser } from './helpers/app'

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

describe('useAccount readings', () => {
  it("passes on the server's reason when a change is refused", async () => {
    stubApi({
      'PATCH /account/readings/a': {
        status: 422,
        body: { error: 'Password must be at least 4 characters.' },
      },
    })

    expect(await useAccount().updateReading('a', { password: 'x' })).toEqual({
      ok: false,
      error: 'Password must be at least 4 characters.',
    })
  })

  it('escapes the reading id in the URL', async () => {
    const api = stubApi({ 'DELETE /account/readings/a%2Fb': { status: 204 } })

    expect(await useAccount().deleteReading('a/b')).toEqual({ ok: true })
    expect(api).toHaveBeenCalledTimes(1)
  })

  it('says why a reading could not be deleted', async () => {
    stubApi({ 'DELETE /account/readings/a': { status: 404 } })

    expect(await useAccount().deleteReading('a')).toEqual({
      ok: false,
      error: 'Could not delete reading.',
    })
  })
})

describe('useAccount profile', () => {
  beforeEach(() => signIn(testUser({ display_name: 'Reader' })))

  it('renames the user and shows the new name everywhere at once', async () => {
    const api = stubApi({
      'PATCH /account': { body: { user: testUser({ display_name: 'Morgan' }) } },
    })

    expect(await useAccount().changeDisplayName('Morgan')).toEqual({ ok: true })

    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual({ display_name: 'Morgan' })
    expect(useUser().currentUser.value?.display_name).toBe('Morgan')
  })

  it('keeps the old name when the new one is refused', async () => {
    stubApi({ 'PATCH /account': { status: 422, body: { error: 'Display name is taken.' } } })

    expect(await useAccount().changeDisplayName('Admin')).toEqual({
      ok: false,
      error: 'Display name is taken.',
    })
    expect(useUser().currentUser.value?.display_name).toBe('Reader')
  })

  it('changes the password with the current one as proof', async () => {
    const api = stubApi({ 'POST /account/change-password': { body: { message: 'Changed.' } } })

    expect(await useAccount().changePassword('old pass', 'a new passphrase')).toEqual({ ok: true })
    expect(JSON.parse(String(api.mock.calls[0]?.[1]?.body))).toEqual({
      current_password: 'old pass',
      new_password: 'a new passphrase',
    })
  })

  it('says why the password was not changed', async () => {
    stubApi({
      'POST /account/change-password': {
        status: 403,
        body: { error: 'Current password is incorrect.' },
      },
    })

    expect(await useAccount().changePassword('wrong', 'a new passphrase')).toEqual({
      ok: false,
      error: 'Current password is incorrect.',
    })
  })

  it('unlinks Google', async () => {
    stubApi({ 'POST /auth/google/unlink': { body: { message: 'Unlinked.' } } })

    expect(await useAccount().unlinkGoogle()).toEqual({ ok: true })
  })
})
