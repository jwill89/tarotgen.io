import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useUserSpreads } from '@/composables/useUserSpreads'
import type { UserSpread } from '@/types'

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

  it('answers null offline instead of throwing', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const result = await useUserSpreads().createUserSpread({
      name: 'X',
      description: '',
      card_count: 1,
      positions: [],
    })

    expect(result).toBeNull()
  })
})
