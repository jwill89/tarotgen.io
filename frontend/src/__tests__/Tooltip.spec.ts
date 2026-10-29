import { describe, it, expect, afterEach } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import Tooltip from '@/components/Tooltip.vue'
import { mountWithApp, cleanup } from './helpers/app'

// App.vue mounts the one TooltipProvider every Tooltip needs.
const Undo = defineComponent({
  setup() {
    return () =>
      h(TooltipProvider, null, () =>
        h(Tooltip, { text: 'Undo', side: 'bottom' }, () =>
          h('button', { type: 'button', 'aria-label': 'Undo' }, '↶'),
        ),
      )
  },
})

const tooltip = () => document.body.querySelector('[role="tooltip"]')

describe('Tooltip', () => {
  afterEach(cleanup)

  it('wraps its control without changing it', async () => {
    const { wrapper } = await mountWithApp(Undo)

    expect(wrapper.findAll('button')).toHaveLength(1)
    expect(wrapper.get('button').attributes('aria-label')).toBe('Undo')
    expect(tooltip()).toBeNull()
  })

  it('shows its text when the control gets keyboard focus, and hides it on blur', async () => {
    const { wrapper } = await mountWithApp(Undo)
    const button = wrapper.get('button')

    await button.trigger('focus')
    await flushPromises()
    expect(tooltip()?.textContent.trim()).toBe('Undo')
    expect(button.attributes('aria-describedby')).toBe(tooltip()?.id)

    await button.trigger('blur')
    await flushPromises()
    expect(tooltip()).toBeNull()
  })

  it('hides on Escape', async () => {
    const { wrapper } = await mountWithApp(Undo)
    await wrapper.get('button').trigger('focus')
    await flushPromises()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(tooltip()).toBeNull()
  })
})
