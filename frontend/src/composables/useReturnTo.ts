import { useRouter, type RouteLocationRaw } from 'vue-router'

/**
 * Leave a page for the one it was opened from (an editor for its list) the way
 * the admin came. When that page is the previous history entry, step back to
 * it, so the history doesn't fill with list/editor pairs and Forward still
 * reopens the editor. When it isn't (the editor was a deep link, a reload, a
 * new tab), replace the editor with it, so Back from there doesn't land on the
 * editor that was just closed.
 */
export function useReturnTo() {
  const router = useRouter()

  return function returnTo(to: RouteLocationRaw): void {
    // The browser history's state, where vue-router records the previous entry's path.
    const { back } = router.options.history.state
    if (typeof back === 'string' && router.resolve(back).path === router.resolve(to).path) {
      router.back()
    } else {
      void router.replace(to)
    }
  }
}
