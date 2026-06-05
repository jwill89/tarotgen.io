import { ref, computed, watch } from 'vue'
import { session } from '@/utils/storage'
import { STORAGE_KEYS } from '@/constants'
import type { User } from '@/types'

const USER_KEY = STORAGE_KEYS.currentUser

// Seed from session storage so nav/guards reflect the logged-in user instantly
// on reload, before the async /me revalidation completes.
const currentUser = ref<User | null>(session.get<User | null>(USER_KEY, null))

// Mirror changes back to session storage.
watch(currentUser, (val) => {
    if (val) {
        session.set(USER_KEY, val)
    } else {
        session.remove(USER_KEY)
    }
}, { deep: true })

const JSON_HEADERS = { 'Content-Type': 'application/json' }

async function parseJson(res: Response): Promise<Record<string, unknown>> {
    try {
        return await res.json() as Record<string, unknown>
    } catch {
        return {}
    }
}

export interface RegisterResult {
    ok: boolean
    errors?: string[]
    message?: string
    /** Present only in non-production when SMTP is unconfigured. */
    activationLink?: string
}

export function useUser() {
    const isLoggedIn = computed(() => currentUser.value !== null)

    /** Revalidate the session against the server. */
    async function fetchMe(): Promise<void> {
        try {
            const res = await fetch('/api/user/me')
            if (res.ok) {
                const data = await parseJson(res)
                currentUser.value = (data.user as User) ?? null
            } else {
                currentUser.value = null
            }
        } catch {
            currentUser.value = null
        }
    }

    async function register(email: string, displayName: string, password: string): Promise<RegisterResult> {
        try {
            const res = await fetch('/api/user/register', {
                method: 'POST',
                headers: JSON_HEADERS,
                body: JSON.stringify({ email, display_name: displayName, password }),
            })
            const data = await parseJson(res)

            if (res.ok) {
                return {
                    ok: true,
                    message: typeof data.message === 'string' ? data.message : undefined,
                    activationLink: typeof data.activation_link === 'string' ? data.activation_link : undefined,
                }
            }

            if (Array.isArray(data.errors)) {
                return { ok: false, errors: data.errors as string[] }
            }
            return { ok: false, errors: [typeof data.error === 'string' ? data.error : 'Registration failed.'] }
        } catch {
            return { ok: false, errors: ['Network error. Please check your connection and try again.'] }
        }
    }

    async function activate(token: string): Promise<{ ok: boolean; message?: string; error?: string }> {
        try {
            const res = await fetch('/api/user/activate', {
                method: 'POST',
                headers: JSON_HEADERS,
                body: JSON.stringify({ token }),
            })
            const data = await parseJson(res)

            if (res.ok) {
                return { ok: true, message: typeof data.message === 'string' ? data.message : undefined }
            }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Activation failed.' }
        } catch {
            return { ok: false, error: 'Network error. Please try again.' }
        }
    }

    async function requestPasswordReset(email: string): Promise<{ ok: boolean; message?: string; resetLink?: string }> {
        try {
            const res = await fetch('/api/user/forgot-password', {
                method: 'POST',
                headers: JSON_HEADERS,
                body: JSON.stringify({ email }),
            })
            const data = await parseJson(res)

            if (res.ok) {
                return {
                    ok: true,
                    message: typeof data.message === 'string' ? data.message : undefined,
                    resetLink: typeof data.reset_link === 'string' ? data.reset_link : undefined,
                }
            }
            return { ok: false, message: typeof data.error === 'string' ? data.error : 'Request failed.' }
        } catch {
            return { ok: false, message: 'Network error. Please try again.' }
        }
    }

    async function resetPassword(token: string, password: string): Promise<{ ok: boolean; message?: string; error?: string }> {
        try {
            const res = await fetch('/api/user/reset-password', {
                method: 'POST',
                headers: JSON_HEADERS,
                body: JSON.stringify({ token, password }),
            })
            const data = await parseJson(res)

            if (res.ok) {
                return { ok: true, message: typeof data.message === 'string' ? data.message : undefined }
            }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Reset failed.' }
        } catch {
            return { ok: false, error: 'Network error. Please try again.' }
        }
    }

    async function login(email: string, password: string, rememberMe = false): Promise<{ ok: boolean; error?: string }> {
        try {
            const res = await fetch('/api/user/login', {
                method: 'POST',
                headers: JSON_HEADERS,
                body: JSON.stringify({ email, password, remember_me: rememberMe }),
            })
            const data = await parseJson(res)

            if (res.ok) {
                currentUser.value = (data.user as User) ?? null
                return { ok: true }
            }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Login failed.' }
        } catch {
            return { ok: false, error: 'Network error. Please check your connection and try again.' }
        }
    }

    async function logout(): Promise<void> {
        try {
            await fetch('/api/user/logout', { method: 'POST' })
        } finally {
            currentUser.value = null
        }
    }

    return { currentUser, isLoggedIn, fetchMe, register, activate, requestPasswordReset, resetPassword, login, logout }
}
