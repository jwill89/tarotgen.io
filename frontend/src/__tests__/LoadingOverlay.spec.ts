import { describe, it, expect, afterEach } from 'vitest'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import { mountWithApp, cleanup } from './helpers/app'

describe('LoadingOverlay', () => {
  afterEach(cleanup)

  it('says what is loading', async () => {
    const { wrapper } = await mountWithApp(LoadingOverlay, {
      props: { message: 'Loading your reading...' },
    })

    expect(wrapper.text()).toBe('Loading your reading...')
  })

  it('falls back to a generic message', async () => {
    const { wrapper } = await mountWithApp(LoadingOverlay)

    expect(wrapper.text()).toBe('Loading...')
  })
})
