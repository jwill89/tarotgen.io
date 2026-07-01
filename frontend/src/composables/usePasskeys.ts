import { ref } from 'vue'
import { useUser } from './useUser'
import { endpoints } from '@/api/endpoints'
import type { User } from '@/types'

export interface Passkey {
    passkey_id: number
    name: string
    created_at: string
    last_used_at: string | null
}

const JSON_HEADERS = { 'Content-Type': 'application/json' }

async function parseJson(res: Response): Promise<Record<string, unknown>> {
    try {
        return await res.json() as Record<string, unknown>
    } catch {
        return {}
    }
}

/**
 * Encode an ArrayBuffer to base64url string.
 */
function bufferToBase64Url(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer)
    let binary = ''
    for (const byte of bytes) binary += String.fromCharCode(byte)
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * Decode a base64url string to an ArrayBuffer.
 */
function base64UrlToBuffer(base64url: string): ArrayBuffer {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/')
    const padded = base64 + '='.repeat((4 - base64.length % 4) % 4)
    const binary = atob(padded)
    const bytes = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
    return bytes.buffer
}

export function usePasskeys() {
    const { currentUser, fetchMe } = useUser()
    const passkeys = ref<Passkey[]>([])
    const loading = ref(false)

    async function listPasskeys(): Promise<void> {
        loading.value = true
        try {
            const res = await fetch('/api' + endpoints.passkeys.list)
            if (res.ok) {
                passkeys.value = await res.json() as Passkey[]
            }
        } finally {
            loading.value = false
        }
    }

    async function registerPasskey(name: string): Promise<{ ok: boolean; error?: string; passkey?: Passkey }> {
        try {
            // 1. Get creation options from server.
            const optRes = await fetch('/api' + endpoints.passkeys.registerOptions, { method: 'POST' })
            if (!optRes.ok) {
                const data = await parseJson(optRes)
                return { ok: false, error: typeof data.error === 'string' ? data.error : 'Failed to get registration options.' }
            }

            const options = await optRes.json()

            // 2. Convert base64url fields to ArrayBuffer for the browser API.
            const publicKey = options.publicKey
            publicKey.challenge = base64UrlToBuffer(publicKey.challenge)
            publicKey.user.id = base64UrlToBuffer(publicKey.user.id)
            if (publicKey.excludeCredentials) {
                for (const cred of publicKey.excludeCredentials) {
                    cred.id = base64UrlToBuffer(cred.id)
                }
            }

            // 3. Call WebAuthn browser API.
            const credential = await navigator.credentials.create({ publicKey }) as PublicKeyCredential | null
            if (!credential) {
                return { ok: false, error: 'Passkey creation was cancelled.' }
            }

            const attestationResponse = credential.response as AuthenticatorAttestationResponse

            // 4. Send attestation to server.
            const regRes = await fetch('/api' + endpoints.passkeys.register, {
                method: 'POST',
                headers: JSON_HEADERS,
                body: JSON.stringify({
                    clientDataJSON: bufferToBase64Url(attestationResponse.clientDataJSON),
                    attestationObject: bufferToBase64Url(attestationResponse.attestationObject),
                    name,
                }),
            })

            const regData = await parseJson(regRes)
            if (regRes.ok) {
                const passkey = regData.passkey as Passkey
                passkeys.value.unshift(passkey)
                await fetchMe() // Refresh user to pick up has_passkeys
                return { ok: true, passkey }
            }
            return { ok: false, error: typeof regData.error === 'string' ? regData.error : 'Registration failed.' }
        } catch (e: unknown) {
            if (e instanceof DOMException && e.name === 'NotAllowedError') {
                return { ok: false, error: 'Passkey creation was cancelled or not allowed.' }
            }
            return { ok: false, error: 'An unexpected error occurred during passkey registration.' }
        }
    }

    async function loginWithPasskey(email?: string): Promise<{ ok: boolean; error?: string }> {
        try {
            // 1. Get assertion options.
            const optRes = await fetch('/api' + endpoints.passkeys.loginOptions, {
                method: 'POST',
                headers: JSON_HEADERS,
                body: JSON.stringify({ email: email ?? '' }),
            })
            if (!optRes.ok) {
                const data = await parseJson(optRes)
                return { ok: false, error: typeof data.error === 'string' ? data.error : 'Failed to get login options.' }
            }

            const options = await optRes.json()
            const publicKey = options.publicKey
            publicKey.challenge = base64UrlToBuffer(publicKey.challenge)
            if (publicKey.allowCredentials) {
                for (const cred of publicKey.allowCredentials) {
                    cred.id = base64UrlToBuffer(cred.id)
                }
            }

            // 2. Call WebAuthn browser API.
            const assertion = await navigator.credentials.get({ publicKey }) as PublicKeyCredential | null
            if (!assertion) {
                return { ok: false, error: 'Passkey authentication was cancelled.' }
            }

            const assertionResponse = assertion.response as AuthenticatorAssertionResponse

            // 3. Send assertion to server.
            const loginRes = await fetch('/api' + endpoints.passkeys.login, {
                method: 'POST',
                headers: JSON_HEADERS,
                body: JSON.stringify({
                    id: bufferToBase64Url(assertion.rawId),
                    clientDataJSON: bufferToBase64Url(assertionResponse.clientDataJSON),
                    authenticatorData: bufferToBase64Url(assertionResponse.authenticatorData),
                    signature: bufferToBase64Url(assertionResponse.signature),
                }),
            })

            const loginData = await parseJson(loginRes)
            if (loginRes.ok) {
                currentUser.value = (loginData.user as User) ?? null
                return { ok: true }
            }
            return { ok: false, error: typeof loginData.error === 'string' ? loginData.error : 'Passkey login failed.' }
        } catch (e: unknown) {
            if (e instanceof DOMException && e.name === 'NotAllowedError') {
                return { ok: false, error: 'Passkey authentication was cancelled or not allowed.' }
            }
            return { ok: false, error: 'An unexpected error occurred during passkey login.' }
        }
    }

    async function renamePasskey(id: number, name: string): Promise<{ ok: boolean; error?: string }> {
        try {
            const res = await fetch('/api' + endpoints.passkeys.byId(id), {
                method: 'PATCH',
                headers: JSON_HEADERS,
                body: JSON.stringify({ name }),
            })
            const data = await parseJson(res)
            if (res.ok) {
                const pk = passkeys.value.find(p => p.passkey_id === id)
                if (pk) pk.name = name
                return { ok: true }
            }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Could not rename passkey.' }
        } catch {
            return { ok: false, error: 'Network error.' }
        }
    }

    async function deletePasskey(id: number): Promise<{ ok: boolean; error?: string }> {
        try {
            const res = await fetch('/api' + endpoints.passkeys.byId(id), { method: 'DELETE' })
            const data = await parseJson(res)
            if (res.ok) {
                passkeys.value = passkeys.value.filter(p => p.passkey_id !== id)
                await fetchMe()
                return { ok: true }
            }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Could not delete passkey.' }
        } catch {
            return { ok: false, error: 'Network error.' }
        }
    }

    async function togglePasswordLogin(disable: boolean): Promise<{ ok: boolean; error?: string }> {
        try {
            const res = await fetch('/api' + endpoints.passkeys.passwordLogin, {
                method: 'PATCH',
                headers: JSON_HEADERS,
                body: JSON.stringify({ disable }),
            })
            const data = await parseJson(res)
            if (res.ok) {
                if (data.user) currentUser.value = data.user as User
                return { ok: true }
            }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Could not update setting.' }
        } catch {
            return { ok: false, error: 'Network error.' }
        }
    }

    /**
     * Check if the browser supports WebAuthn / passkeys.
     */
    function isSupported(): boolean {
        return !!(window.PublicKeyCredential && navigator.credentials)
    }

    return {
        passkeys,
        loading,
        isSupported,
        listPasskeys,
        registerPasskey,
        loginWithPasskey,
        renamePasskey,
        deletePasskey,
        togglePasswordLogin,
    }
}

