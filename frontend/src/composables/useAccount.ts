import { useUser } from './useUser'
import { endpoints } from '@/api/endpoints'
import { apiRequest, sendJson } from './apiClient'
import type { AccountReading, User } from '@/types'

export interface ReadingMetaUpdate {
  reading_name?: string
  hide_user?: boolean
  password?: string
  remove_password?: boolean
}

/** Signed-in user's self-service API (/api/account/*). */
export function useAccount() {
  const { currentUser } = useUser()

  /**
   * One page of the user's readings, newest first, plus their total count. A
   * failure comes back as `{ ok: false, error }` rather than an empty list, so
   * the page can't mistake "couldn't load" for "no readings yet".
   */
  async function listReadingsPage(
    page: number,
    perPage: number,
  ): Promise<
    { ok: true; readings: AccountReading[]; total: number } | { ok: false; error: string }
  > {
    const offset = (Math.max(1, page) - 1) * perPage
    const res = await apiRequest<AccountReading[]>(
      `${endpoints.account.readings}?limit=${perPage}&offset=${offset}`,
    )
    if (!res.ok) return { ok: false, error: res.error }

    // get() returns null when absent, and Number(null) is 0 — parse only a real value.
    const header = res.headers?.get('X-Total-Count')
    const total = header ? Number(header) : NaN
    return {
      ok: true,
      readings: res.data,
      // A proxy that strips the header must not break paging: fall back to
      // "at least what we can see".
      total: Number.isFinite(total) ? total : offset + res.data.length,
    }
  }

  async function updateReading(
    id: string,
    payload: ReadingMetaUpdate,
  ): Promise<{ ok: boolean; reading?: AccountReading; error?: string }> {
    const res = await apiRequest<AccountReading>(
      endpoints.account.readingById(encodeURIComponent(id)),
      sendJson('PATCH', payload),
      'Update failed.',
    )
    return res.ok ? { ok: true, reading: res.data } : { ok: false, error: res.error }
  }

  async function deleteReading(id: string): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.account.readingById(encodeURIComponent(id)),
      { method: 'DELETE' },
      'Could not delete reading.',
    )
    return res.ok ? { ok: true } : { ok: false, error: res.error }
  }

  async function changeDisplayName(displayName: string): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest<{ user?: User }>(
      endpoints.account.profile,
      sendJson('PATCH', { display_name: displayName }),
      'Could not update display name.',
    )
    if (!res.ok) return { ok: false, error: res.error }
    if (res.data.user) currentUser.value = res.data.user
    return { ok: true }
  }

  async function changePassword(
    currentPassword: string,
    newPassword: string,
  ): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.account.changePassword,
      sendJson('POST', { current_password: currentPassword, new_password: newPassword }),
      'Could not change password.',
    )
    return res.ok ? { ok: true } : { ok: false, error: res.error }
  }

  async function deleteAccount(password: string): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.account.root,
      sendJson('DELETE', { password }),
      'Could not delete account.',
    )
    if (!res.ok) return { ok: false, error: res.error }
    currentUser.value = null
    return { ok: true }
  }

  /** Detach the linked Google account (refused while it's the account's only way to sign in). */
  async function unlinkGoogle(): Promise<{ ok: boolean; error?: string }> {
    const res = await apiRequest(
      endpoints.authGoogle.unlink,
      { method: 'POST' },
      'Could not unlink Google account.',
    )
    return res.ok ? { ok: true } : { ok: false, error: res.error }
  }

  return {
    listReadingsPage,
    updateReading,
    deleteReading,
    changeDisplayName,
    changePassword,
    deleteAccount,
    unlinkGoogle,
  }
}
