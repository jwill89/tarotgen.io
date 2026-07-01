import { useUser } from './useUser'
import { endpoints } from '@/api/endpoints'
import type { AccountReading, User } from '@/types'

const JSON_HEADERS = { 'Content-Type': 'application/json' }

async function parseJson(res: Response): Promise<Record<string, unknown>> {
    try {
        return await res.json() as Record<string, unknown>
    } catch {
        return {}
    }
}

export interface ReadingMetaUpdate {
    reading_name?: string
    hide_user?: boolean
    password?: string
    remove_password?: boolean
}

/** Signed-in user's self-service API (/api/account/*). */
export function useAccount() {
    const { currentUser } = useUser()

    async function listReadings(): Promise<AccountReading[]> {
        try {
            const res = await fetch('/api' + endpoints.account.readings)
            if (!res.ok) return []
            return await res.json() as AccountReading[]
        } catch {
            return []
        }
    }

    async function updateReading(id: string, payload: ReadingMetaUpdate): Promise<{ ok: boolean; reading?: AccountReading; error?: string }> {
        try {
            const res = await fetch('/api' + endpoints.account.readingById(encodeURIComponent(id)), {
                method: 'PATCH',
                headers: JSON_HEADERS,
                body: JSON.stringify(payload),
            })
            const data = await parseJson(res)
            if (res.ok) return { ok: true, reading: data as unknown as AccountReading }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Update failed.' }
        } catch {
            return { ok: false, error: 'Network error. Please try again.' }
        }
    }

    async function deleteReading(id: string): Promise<{ ok: boolean; error?: string }> {
        try {
            const res = await fetch('/api' + endpoints.account.readingById(encodeURIComponent(id)), { method: 'DELETE' })
            const data = await parseJson(res)
            if (res.ok) return { ok: true }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Could not delete reading.' }
        } catch {
            return { ok: false, error: 'Network error. Please try again.' }
        }
    }

    async function changeDisplayName(displayName: string): Promise<{ ok: boolean; error?: string }> {
        try {
            const res = await fetch('/api' + endpoints.account.profile, {
                method: 'PATCH',
                headers: JSON_HEADERS,
                body: JSON.stringify({ display_name: displayName }),
            })
            const data = await parseJson(res)
            if (res.ok) {
                if (data.user) currentUser.value = data.user as User
                return { ok: true }
            }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Could not update display name.' }
        } catch {
            return { ok: false, error: 'Network error. Please try again.' }
        }
    }

    async function changePassword(currentPassword: string, newPassword: string): Promise<{ ok: boolean; error?: string }> {
        try {
            const res = await fetch('/api' + endpoints.account.changePassword, {
                method: 'POST',
                headers: JSON_HEADERS,
                body: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
            })
            const data = await parseJson(res)
            if (res.ok) return { ok: true }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Could not change password.' }
        } catch {
            return { ok: false, error: 'Network error. Please try again.' }
        }
    }

    async function deleteAccount(password: string): Promise<{ ok: boolean; error?: string }> {
        try {
            const res = await fetch('/api' + endpoints.account.root, {
                method: 'DELETE',
                headers: JSON_HEADERS,
                body: JSON.stringify({ password }),
            })
            const data = await parseJson(res)
            if (res.ok) {
                currentUser.value = null
                return { ok: true }
            }
            return { ok: false, error: typeof data.error === 'string' ? data.error : 'Could not delete account.' }
        } catch {
            return { ok: false, error: 'Network error. Please try again.' }
        }
    }

    return { listReadings, updateReading, deleteReading, changeDisplayName, changePassword, deleteAccount }
}
