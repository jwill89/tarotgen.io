import { describe, it, expect, vi } from 'vitest'
import { useReadings } from '@/composables/useReadings'

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

describe('useReadings', () => {
  it('generates from form fields, as the API has always received them', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(fakeResponse({ reading_id: 'abc', reading_time: 't' }, { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)

    const res = await useReadings().generate(
      new URLSearchParams({ deck_id: '1', number_of_cards: '3' }),
    )

    expect(fetchMock).toHaveBeenCalledWith('/api/readings/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'deck_id=1&number_of_cards=3',
    })
    expect(res.ok && res.data.reading_id).toBe('abc')
  })

  it('escapes a reading id that came from the address bar', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ locked: true }))
    vi.stubGlobal('fetch', fetchMock)

    await useReadings().get('a/b?c')

    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/readings/a%2Fb%3Fc')
  })

  it('passes a wrong-password message through, or falls back to one', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 403 })),
    )

    const res = await useReadings().unlock('abc', 'guess')

    expect(!res.ok && res.error).toBe('Incorrect password.')
  })

  it('saves a placement as JSON under the spread name', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ success: true }))
    vi.stubGlobal('fetch', fetchMock)
    const positions = [{ title: 'Past', x: 20, y: 50, rotation: 0 }]

    await useReadings().savePlacement('abc', positions, 'My Layout')

    expect(fetchMock).toHaveBeenCalledWith('/api/readings/abc/placement', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ positions, spread_name: 'My Layout' }),
    })
  })

  it("names what failed when the server doesn't", async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse(null, { ok: false, status: 500 })),
    )

    const res = await useReadings().finalize('abc')

    expect(!res.ok && res.error).toBe('Could not finalize the reading.')
  })
})
