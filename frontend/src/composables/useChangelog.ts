import { ref, type Ref } from 'vue'
import type { ChangelogEntry } from '@/types'
import { apiFetch } from './useApi'

const entries: Ref<ChangelogEntry[]> = ref([])

export function useChangelog() {
    async function fetchChangelog(): Promise<void> {
        const data = await apiFetch<ChangelogEntry[]>('/changelog/')
        if (data) entries.value = data
    }

    return { entries, fetchChangelog }
}
