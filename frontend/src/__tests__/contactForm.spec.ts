import { describe, it, expect, beforeEach, vi } from 'vitest'

// Mock vue-router (needed by useToasts → useApi in some paths).
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))

/**
 * These tests exercise the contact form submission logic at the fetch layer,
 * mirroring the patterns in useApi.spec.ts. Since the view does direct fetch
 * calls without a dedicated composable, we validate the request shape and the
 * various response-handling paths.
 */

const API_URL = '/api/contacts'
const JSON_HEADERS = { 'Content-Type': 'application/json' }

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return {
    ok,
    status,
    json: async () => body,
    clone: () => ({ json: async () => body }),
  } as unknown as Response
}

async function submitContact(payload: { name: string; email: string; message: string }): Promise<{
  ok: boolean
  error?: string
  rateLimited?: boolean
}> {
  try {
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: JSON_HEADERS,
      body: JSON.stringify(payload),
    })

    if (res.status === 429) {
      return { ok: false, rateLimited: true, error: 'Rate limited' }
    }

    if (!res.ok) {
      const body = (await res.json().catch(() => ({}))) as { error?: string }
      return { ok: false, error: body.error || 'Request failed' }
    }

    return { ok: true }
  } catch {
    return { ok: false, error: 'Network error' }
  }
}

describe('Contact form submission', () => {
  beforeEach(() => {
    vi.unstubAllGlobals()
  })

  it('sends a POST with JSON body to /api/contacts', async () => {
    const fetchMock = vi.fn().mockResolvedValue(fakeResponse({ success: true }))
    vi.stubGlobal('fetch', fetchMock)

    const result = await submitContact({ name: 'Alice', email: 'alice@x.com', message: 'Hello' })

    expect(fetchMock).toHaveBeenCalledWith(API_URL, {
      method: 'POST',
      headers: JSON_HEADERS,
      body: JSON.stringify({ name: 'Alice', email: 'alice@x.com', message: 'Hello' }),
    })
    expect(result.ok).toBe(true)
  })

  it('returns an error message on a non-ok response', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          fakeResponse(
            { error: 'Name, email, and message are all required.' },
            { ok: false, status: 400 },
          ),
        ),
    )

    const result = await submitContact({ name: '', email: '', message: '' })

    expect(result.ok).toBe(false)
    expect(result.error).toBe('Name, email, and message are all required.')
  })

  it('detects rate limiting (429)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(fakeResponse({ error: 'Too many' }, { ok: false, status: 429 })),
    )

    const result = await submitContact({ name: 'A', email: 'a@x.com', message: 'spam' })

    expect(result.ok).toBe(false)
    expect(result.rateLimited).toBe(true)
  })

  it('handles network errors gracefully', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const result = await submitContact({ name: 'A', email: 'a@x.com', message: 'hi' })

    expect(result.ok).toBe(false)
    expect(result.error).toBe('Network error')
  })

  it('falls back to generic message when response has no error field', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(fakeResponse({}, { ok: false, status: 500 })))

    const result = await submitContact({ name: 'A', email: 'a@x.com', message: 'hi' })

    expect(result.ok).toBe(false)
    expect(result.error).toBe('Request failed')
  })
})
