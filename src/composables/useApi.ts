import { useUser } from './useUser'
import { useToasts } from './useToasts'
import { useRouter } from 'vue-router'

/**
 * Pull a human-readable message out of a failed API response. The backend
 * returns `{ "error": "..." }` bodies; fall back to a caller-supplied default
 * when the body is missing or unparseable.
 */
export async function readApiError(res: Response, fallback: string): Promise<string> {
    try {
        const body = await res.clone().json() as { error?: unknown }
        if (body && typeof body.error === 'string' && body.error.trim() !== '') {
            return body.error
        }
    } catch {
        // Non-JSON or empty body — use the fallback.
    }
    return fallback
}

/**
 * Generic fetch helper for public API calls.
 */
export async function apiFetch<T>(path: string, options?: RequestInit): Promise<T | null> {
    try {
        const res = await fetch('/api' + path, options)
        if (!res.ok) return null
        return await res.json() as T
    } catch {
        return null
    }
}

/**
 * Composable for admin API calls with automatic 401 handling.
 */
export function useAdminApi() {
    const { currentUser } = useUser()
    const { error: toastError, success: toastSuccess } = useToasts()
    const router = useRouter()

    async function request<T>(
        path: string,
        options: RequestInit = {},
        successMessage?: string,
    ): Promise<T | null> {
        try {
            const res = await fetch('/api/admin' + path, {
                headers: { 'Content-Type': 'application/json' },
                ...options,
            })

            if (res.status === 401) {
                // Session expired or admin was revoked — drop to the login screen.
                currentUser.value = null
                router.push({ name: 'login' })
                return null
            }

            // Don't hand error bodies (404/500/etc.) back as if they succeeded.
            // Surface the server's message so admins see why an action failed.
            if (!res.ok) {
                toastError(await readApiError(res, 'Request failed. Please try again.'))
                return null
            }

            const data = await res.json() as T
            if (successMessage) toastSuccess(successMessage)
            return data
        } catch (e) {
            toastError('Network error. Please check your connection and try again.', { detail: e })
            return null
        }
    }

    function get<T>(path: string) {
        return request<T>(path)
    }

    function post<T>(path: string, data: unknown, successMessage?: string) {
        return request<T>(path, { method: 'POST', body: JSON.stringify(data) }, successMessage)
    }

    function put<T>(path: string, data: unknown, successMessage?: string) {
        return request<T>(path, { method: 'PUT', body: JSON.stringify(data) }, successMessage)
    }

    function del<T>(path: string, successMessage?: string) {
        return request<T>(path, { method: 'DELETE' }, successMessage)
    }

    return { get, post, put, del }
}
