import { useUser } from './useUser'
import { useToasts } from './useToasts'
import { useRouter } from 'vue-router'
import { apiRequest } from './apiClient'

// Re-export the framework-free client so existing imports from '@/composables/useApi'
// keep working (apiFetch, apiRequest, readApiError, types, …).
export * from './apiClient'

const JSON_HEADERS = { 'Content-Type': 'application/json' }

/**
 * Composable for admin API calls: auto-handles 401 (drop to login), toasts the
 * server's error message on other failures, and toasts a success message when
 * one is provided. All paths are relative to `/api/admin`.
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
        const res = await apiRequest<T>('/admin' + path, { headers: JSON_HEADERS, ...options })

        if (!res.ok) {
            if (res.status === 401) {
                // Session expired or admin was revoked — drop to the login screen.
                currentUser.value = null
                router.push({ name: 'login' })
                return null
            }
            // Surface the server's message (or the transport error) so admins see
            // why an action failed; log the body/status for post-mortem.
            toastError(res.error, { detail: { status: res.status, body: res.data } })
            return null
        }

        if (successMessage) toastSuccess(successMessage)
        return res.data
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
