import { ref, type Ref } from 'vue'
import type { UserSpread } from '@/types'
import { apiFetch } from './useApi'
import { endpoints } from '@/api/endpoints'

const userSpreads: Ref<UserSpread[]> = ref([])

export function useUserSpreads() {
    async function fetchUserSpreads(): Promise<void> {
        const data = await apiFetch<UserSpread[]>(endpoints.account.spreads)
        if (data) userSpreads.value = data
    }

    async function createUserSpread(payload: {
        name: string
        description: string
        card_count: number
        positions: unknown[]
    }): Promise<UserSpread | null> {
        const res = await fetch('/api' + endpoints.account.spreads, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        if (!res.ok) return null
        const spread = await res.json() as UserSpread
        userSpreads.value = [spread, ...userSpreads.value]
        return spread
    }

    async function updateUserSpread(
        id: number,
        payload: { name?: string; description?: string; card_count?: number; positions?: unknown[] }
    ): Promise<UserSpread | null> {
        const res = await fetch('/api' + endpoints.account.spreadById(id), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        if (!res.ok) return null
        const updated = await res.json() as UserSpread
        userSpreads.value = userSpreads.value.map(s =>
            s.user_spread_id === id ? updated : s
        )
        return updated
    }

    async function deleteUserSpread(id: number): Promise<boolean> {
        const res = await fetch('/api' + endpoints.account.spreadById(id), { method: 'DELETE' })
        if (!res.ok) return false
        userSpreads.value = userSpreads.value.filter(s => s.user_spread_id !== id)
        return true
    }

    async function submitAsPublic(id: number): Promise<boolean> {
        const res = await fetch('/api' + endpoints.account.spreadSubmit(id), { method: 'POST' })
        return res.ok
    }

    return {
        userSpreads,
        fetchUserSpreads,
        createUserSpread,
        updateUserSpread,
        deleteUserSpread,
        submitAsPublic,
    }
}

