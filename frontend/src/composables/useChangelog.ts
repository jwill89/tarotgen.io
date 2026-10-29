import { ref, type Ref } from 'vue'
import type { ChangelogEntry } from '@/types'
import { apiRequest } from './useApi'
import { endpoints } from '@/api/endpoints'

const entries: Ref<ChangelogEntry[]> = ref([])
// Until the first answer the page can't say "no entries yet", and after a failed
// one it has to say why there's nothing to show.
const loaded = ref(false)
const error = ref('')

export function useChangelog() {
  async function fetchChangelog(): Promise<void> {
    const res = await apiRequest<ChangelogEntry[]>(endpoints.changelog.list)
    if (res.ok) {
      entries.value = res.data
      loaded.value = true
      error.value = ''
    } else {
      error.value = res.error
    }
  }

  return { entries, loaded, error, fetchChangelog }
}
