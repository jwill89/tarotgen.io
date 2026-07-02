import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useToasts } from '@/composables/useToasts'

const { toasts, success, error, warning, info, dismiss } = useToasts()

describe('useToasts', () => {
  beforeEach(() => {
    // Drain the shared queue between tests.
    toasts.value.slice().forEach((t) => dismiss(t.id))
  })

  it('queues a toast with the given type and message', () => {
    success('Saved')
    const last = toasts.value.at(-1)
    expect(last).toMatchObject({ type: 'success', message: 'Saved' })
  })

  it('mirrors errors to console.error', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {})
    error('Boom', { detail: { code: 42 } })
    expect(spy).toHaveBeenCalled()
  })

  it('mirrors warnings to console.warn', () => {
    const spy = vi.spyOn(console, 'warn').mockImplementation(() => {})
    warning('Careful')
    expect(spy).toHaveBeenCalled()
  })

  it('does not log successes to the console', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {})
    success('ok')
    info('fyi')
    expect(spy).not.toHaveBeenCalled()
  })

  it('dismiss removes a toast by id', () => {
    const id = info('hello')
    dismiss(id)
    expect(toasts.value.find((t) => t.id === id)).toBeUndefined()
  })

  it('auto-dismisses after the configured duration', () => {
    vi.useFakeTimers()
    try {
      const id = success('bye', { duration: 1000 })
      expect(toasts.value.find((t) => t.id === id)).toBeDefined()
      vi.advanceTimersByTime(1001)
      expect(toasts.value.find((t) => t.id === id)).toBeUndefined()
    } finally {
      vi.useRealTimers()
    }
  })

  it('does not auto-dismiss when duration is 0', () => {
    vi.useFakeTimers()
    try {
      const id = error('stays', { duration: 0 })
      vi.advanceTimersByTime(60000)
      expect(toasts.value.find((t) => t.id === id)).toBeDefined()
    } finally {
      vi.useRealTimers()
    }
  })
})
