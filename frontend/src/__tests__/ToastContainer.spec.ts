import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import ToastContainer from '@/components/ToastContainer.vue'
import { useToasts } from '@/composables/useToasts'
import { mountWithApp, cleanup } from './helpers/app'

const toasts = useToasts()

const shown = () =>
  [...document.body.querySelectorAll('.toast-message')].map((t) => t.textContent.trim())

describe('ToastContainer', () => {
  beforeEach(() => {
    toasts.toasts.value = []
    // Errors and warnings are mirrored to the console; keep the test output clean.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(async () => {
    vi.useRealTimers()
    await cleanup()
  })

  it('shows each queued message, in order, in a labelled notifications region', async () => {
    await mountWithApp(ToastContainer)

    toasts.success('Reading saved.')
    toasts.error('Could not load the decks.')
    await flushPromises()

    expect(shown()).toEqual(['Reading saved.', 'Could not load the decks.'])
    expect(document.body.querySelector('[role="region"]')?.getAttribute('aria-label')).toMatch(
      /^Notifications/,
    )
  })

  it('removes a toast when its dismiss button is pressed', async () => {
    vi.useFakeTimers()
    await mountWithApp(ToastContainer)
    toasts.info('Link copied.')
    toasts.info('Second')
    await flushPromises()

    document.body.querySelector<HTMLButtonElement>('button[aria-label="Dismiss"]')?.click()
    // Dropped from the queue after a beat, so its close animation can play.
    await vi.advanceTimersByTimeAsync(200)

    expect(toasts.toasts.value.map((t) => t.message)).toEqual(['Second'])
    expect(shown()).toEqual(['Second'])
  })

  it('dismisses a toast by itself once its time is up', async () => {
    vi.useFakeTimers()
    await mountWithApp(ToastContainer)
    toasts.success('Saved', { duration: 1000 })
    await flushPromises()

    await vi.advanceTimersByTimeAsync(900)
    expect(shown()).toEqual(['Saved'])

    await vi.advanceTimersByTimeAsync(400)
    expect(toasts.toasts.value).toEqual([])
    expect(shown()).toEqual([])
  })

  it('keeps a toast with no duration until it is dismissed', async () => {
    vi.useFakeTimers()
    await mountWithApp(ToastContainer)
    toasts.error('Stays put', { duration: 0 })
    await flushPromises()

    // Well past the longest default (an error's 8 s).
    await vi.advanceTimersByTimeAsync(30_000)

    expect(shown()).toEqual(['Stays put'])
  })
})
