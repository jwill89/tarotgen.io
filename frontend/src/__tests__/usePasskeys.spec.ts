import { describe, it, expect, vi } from 'vitest'
import { usePasskeys } from '@/composables/usePasskeys'
import { useUser } from '@/composables/useUser'
import type { User } from '@/types'

function fakeResponse(body: unknown, { ok = true, status = 200 } = {}): Response {
  return { ok, status, json: async () => body } as unknown as Response
}

/** A fetch stub that answers by URL and records what was sent. */
function routes(table: Record<string, Response>) {
  const fetchMock = vi.fn((url: string) =>
    Promise.resolve(table[url] ?? fakeResponse({ error: 'unrouted' }, { ok: false, status: 404 })),
  )
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

const bytes = (...b: number[]) => new Uint8Array(b).buffer
const asBytes = (buf: unknown) => [...new Uint8Array(buf as ArrayBuffer)]
const user = { user_id: 1, display_name: 'Reader', has_passkeys: true } as User

describe('usePasskeys.registerPasskey', () => {
  it('hands WebAuthn bytes, sends the attestation back as base64url, and lists the new key', async () => {
    const fetchMock = routes({
      '/api/auth/passkeys/register/options': fakeResponse({
        publicKey: {
          challenge: 'AQID',
          rp: { name: 'TarotGen.io' },
          user: { id: 'BAUG', name: 'reader', displayName: 'Reader' },
          pubKeyCredParams: [],
          excludeCredentials: [{ type: 'public-key', id: 'BwgJ' }],
        },
      }),
      '/api/auth/passkeys/register': fakeResponse({
        passkey: { passkey_id: 9, name: 'Laptop', created_at: 'now', last_used_at: null },
      }),
      '/api/auth/me': fakeResponse({ user }),
    })
    const create = vi.fn().mockResolvedValue({
      response: { clientDataJSON: bytes(10), attestationObject: bytes(11, 12) },
    })
    vi.stubGlobal('navigator', { credentials: { create } })

    const passkeys = usePasskeys()
    const result = await passkeys.registerPasskey('Laptop')

    const { publicKey } = create.mock.calls[0]?.[0] as CredentialCreationOptions & {
      publicKey: PublicKeyCredentialCreationOptions
    }
    expect(asBytes(publicKey.challenge)).toEqual([1, 2, 3])
    expect(asBytes(publicKey.user.id)).toEqual([4, 5, 6])
    expect(asBytes(publicKey.excludeCredentials?.[0]?.id)).toEqual([7, 8, 9])

    const sent = fetchMock.mock.calls.find(([url]) => url === '/api/auth/passkeys/register')
    expect(JSON.parse((sent?.[1] as RequestInit).body as string)).toEqual({
      clientDataJSON: 'Cg',
      attestationObject: 'Cww',
      name: 'Laptop',
    })
    expect(result.ok).toBe(true)
    expect(passkeys.passkeys.value.map((p) => p.passkey_id)).toEqual([9])
    expect(useUser().currentUser.value?.has_passkeys).toBe(true)
  })

  it("shows the server's reason when it won't start a registration", async () => {
    routes({
      '/api/auth/passkeys/register/options': fakeResponse(
        { error: 'Not authenticated' },
        { ok: false, status: 401 },
      ),
    })

    expect(await usePasskeys().registerPasskey('Laptop')).toEqual({
      ok: false,
      error: 'Not authenticated',
    })
  })

  it('reports a cancelled prompt', async () => {
    routes({
      '/api/auth/passkeys/register/options': fakeResponse({
        publicKey: {
          challenge: 'AQID',
          rp: { name: 'T' },
          user: { id: 'BAUG' },
          pubKeyCredParams: [],
        },
      }),
    })
    vi.stubGlobal('navigator', {
      credentials: { create: vi.fn().mockRejectedValue(new DOMException('no', 'NotAllowedError')) },
    })

    const result = await usePasskeys().registerPasskey('Laptop')

    expect(result).toEqual({ ok: false, error: 'Passkey creation was cancelled or not allowed.' })
  })
})

describe('usePasskeys.loginWithPasskey', () => {
  it('signs in with the assertion and keeps the returned user', async () => {
    const fetchMock = routes({
      '/api/auth/passkeys/login/options': fakeResponse({
        publicKey: { challenge: 'AQID', allowCredentials: [{ type: 'public-key', id: 'BAUG' }] },
      }),
      '/api/auth/passkeys/login': fakeResponse({ success: true, user }),
    })
    const get = vi.fn().mockResolvedValue({
      rawId: bytes(1),
      response: { clientDataJSON: bytes(2), authenticatorData: bytes(3), signature: bytes(4) },
    })
    vi.stubGlobal('navigator', { credentials: { get } })
    useUser().currentUser.value = null

    const result = await usePasskeys().loginWithPasskey('reader@example.test')

    const { publicKey } = get.mock.calls[0]?.[0] as { publicKey: PublicKeyCredentialRequestOptions }
    expect(asBytes(publicKey.challenge)).toEqual([1, 2, 3])
    expect(asBytes(publicKey.allowCredentials?.[0]?.id)).toEqual([4, 5, 6])
    const sent = fetchMock.mock.calls.find(([url]) => url === '/api/auth/passkeys/login')
    expect(JSON.parse((sent?.[1] as RequestInit).body as string)).toEqual({
      id: 'AQ',
      clientDataJSON: 'Ag',
      authenticatorData: 'Aw',
      signature: 'BA',
    })
    expect(result).toEqual({ ok: true })
    expect(useUser().currentUser.value?.user_id).toBe(1)
  })
})

describe('usePasskeys management', () => {
  it('renames locally only once the server agrees', async () => {
    routes({
      '/api/auth/passkeys': fakeResponse([{ passkey_id: 3, name: 'Old' }]),
      '/api/auth/passkeys/3': fakeResponse({ error: 'Name too long.' }, { ok: false, status: 400 }),
    })
    const passkeys = usePasskeys()
    await passkeys.listPasskeys()

    const result = await passkeys.renamePasskey(3, 'x'.repeat(300))

    expect(result).toEqual({ ok: false, error: 'Name too long.' })
    expect(passkeys.passkeys.value[0]?.name).toBe('Old')
  })

  it('reports a network failure instead of throwing', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))

    const result = await usePasskeys().deletePasskey(3)

    expect(result.ok).toBe(false)
    expect(result.error).toMatch(/Network error/)
  })
})
