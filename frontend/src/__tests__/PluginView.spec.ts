import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import PluginView from '@/views/PluginView.vue'
import { useToasts } from '@/composables/useToasts'
import { mountWithApp, cleanup, stubApi } from './helpers/app'

const REPO_URL = 'https://tarotgen.io/plugin/repo.json'
const toastMessages = () => useToasts().toasts.value.map((t) => t.message)

async function copy() {
  document.body
    .querySelector<HTMLButtonElement>('button[aria-label="Copy repository URL"]')
    ?.click()
  await flushPromises()
}

describe('PluginView', () => {
  beforeEach(() => {
    useToasts().toasts.value = []
    stubApi({})
  })
  afterEach(cleanup)

  it('shows the custom repository URL to paste into Dalamud', async () => {
    await mountWithApp(PluginView, { path: '/ffxiv-plugin' })

    expect(document.body.querySelector('.repo-url-code')?.textContent).toBe(REPO_URL)
  })

  it('copies the repository URL and says so', async () => {
    const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue()
    await mountWithApp(PluginView, { path: '/ffxiv-plugin' })

    await copy()

    expect(writeText).toHaveBeenCalledWith(REPO_URL)
    expect(toastMessages()).toEqual(['Repository URL copied to your clipboard.'])
  })

  it('asks the user to copy by hand when the clipboard is refused', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(
      new DOMException('Denied', 'NotAllowedError'),
    )
    await mountWithApp(PluginView, { path: '/ffxiv-plugin' })

    await copy()

    expect(toastMessages()).toEqual(['Could not copy — select the URL and copy it manually.'])
  })

  it('points to account linking and help', async () => {
    await mountWithApp(PluginView, { path: '/ffxiv-plugin' })

    const hrefs = [...document.body.querySelectorAll('a')].map((a) => a.getAttribute('href'))
    expect(hrefs).toEqual(
      expect.arrayContaining(['/account/readings', '/account/settings', '/contact']),
    )
  })
})
