import { ref } from 'vue'
import { useAccount } from './useAccount'
import type { ReadingTag } from '@/types'

/**
 * The signed-in reader's own tags, as suggestions for a tag field. Fetched the
 * first time they're wanted (the field gaining focus), not by every page that
 * shows one, and once per page.
 */
export function useReadingTags() {
  const { listTags } = useAccount()
  const tags = ref<ReadingTag[]>([])
  let loading: Promise<void> | null = null

  function load(): Promise<void> {
    loading ??= listTags().then((list) => {
      tags.value = list
    })
    return loading
  }

  return { tags, load }
}
