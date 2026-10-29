import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useUserSpreads } from '@/composables/useUserSpreads'
import type { UserSpread } from '@/types'
import { stubApi } from './helpers/app'

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

const spread = (id: number, name: string) => ({ user_spread_id: id, name }) as UserSpread

describe('useUserSpreads', () => {
  beforeEach(() => {
    // The list is module-wide state shared by every page that uses it.
    useUserSpreads().userSpreads.value = [spread(1, 'One'), spread(2, 'Two')]
  })

  it('puts a new spread first', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(spread(3, 'Three'))))
    const { createUserSpread, userSpreads } = useUserSpreads()

    await createUserSpread({ name: 'Three', description: '', card_count: 1, positions: [] })

    expect(userSpreads.value.map((s) => s.user_spread_id)).toEqual([3, 1, 2])
  })

  it('replaces an edited spread in place', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse(spread(2, 'Renamed'))))
    const { updateUserSpread, userSpreads } = useUserSpreads()

    await updateUserSpread(2, { name: 'Renamed' })

    expect(userSpreads.value.map((s) => s.name)).toEqual(['One', 'Renamed'])
  })

  it('keeps the list as it was when the server refuses', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 403 })),
    )
    const { deleteUserSpread, userSpreads } = useUserSpreads()

    expect(await deleteUserSpread(1)).toBe(false)
    expect(userSpreads.value).toHaveLength(2)
  })

  it('answers with a reason offline instead of throwing', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const result = await useUserSpreads().createUserSpread({
      name: 'X',
      description: '',
      card_count: 1,
      positions: [],
    })

    expect(result).toMatchObject({
      ok: false,
      networkError: true,
      error: 'Network error. Please check your connection and try again.',
    })
  })
})

describe('useUserSpreads loading and sharing', () => {
  beforeEach(() => {
    useUserSpreads().userSpreads.value = [spread(1, 'One')]
  })

  it("loads the user's spreads", async () => {
    stubApi({ 'GET /account/spreads': { body: [spread(4, 'Four'), spread(5, 'Five')] } })
    const { fetchUserSpreads, userSpreads } = useUserSpreads()

    await fetchUserSpreads()

    expect(userSpreads.value.map((s) => s.name)).toEqual(['Four', 'Five'])
  })

  it('keeps the list it has when a reload fails', async () => {
    stubApi({ 'GET /account/spreads': { status: 500 } })
    const { fetchUserSpreads, userSpreads } = useUserSpreads()

    await fetchUserSpreads()

    expect(userSpreads.value.map((s) => s.name)).toEqual(['One'])
  })

  it('takes a deleted spread off the list', async () => {
    stubApi({ 'DELETE /account/spreads/1': { status: 204 } })
    const { deleteUserSpread, userSpreads } = useUserSpreads()

    expect(await deleteUserSpread(1)).toBe(true)
    expect(userSpreads.value).toEqual([])
  })

  it("leaves the list alone when an edit is refused, and says why in the server's words", async () => {
    stubApi({
      'PUT /account/spreads/1': { status: 400, body: { error: 'A spread needs a name.' } },
    })
    const { updateUserSpread, userSpreads } = useUserSpreads()

    expect(await updateUserSpread(1, { name: '' })).toMatchObject({
      ok: false,
      error: 'A spread needs a name.',
    })
    expect(userSpreads.value.map((s) => s.name)).toEqual(['One'])
  })

  it('falls back to a plain message when a refused edit has none', async () => {
    stubApi({ 'PUT /account/spreads/1': { status: 500 } })

    expect(await useUserSpreads().updateUserSpread(1, { name: 'X' })).toMatchObject({
      ok: false,
      error: 'Failed to save changes. Please try again.',
    })
  })

  it('submits a spread for review and reports whether it went', async () => {
    stubApi({ 'POST /account/spreads/1/submit': { status: 201 } })
    expect(await useUserSpreads().submitAsPublic(1)).toBe(true)

    stubApi({
      'POST /account/spreads/1/submit': { status: 409, body: { error: 'Already submitted.' } },
    })
    expect(await useUserSpreads().submitAsPublic(1)).toBe(false)
  })
})
