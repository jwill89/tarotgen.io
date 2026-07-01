import { ref, type Ref } from 'vue'
import type { ChangelogEntry } from '@/types'
import { apiFetch } from './useApi'
import { endpoints } from '@/api/endpoints'

const entries: Ref<ChangelogEntry[]> = ref([])

export function useChangelog() {
    async function fetchChangelog(): Promise<void> {
        const data = await apiFetch<ChangelogEntry[]>(endpoints.changelog.list)
        if (data) entries.value = data
    }

    return { entries, fetchChangelog }
}
