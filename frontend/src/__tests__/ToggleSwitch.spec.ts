import { describe, it, expect, afterEach } from 'vitest'
import ToggleSwitch from '@/components/ToggleSwitch.vue'
import { mountWithApp, cleanup } from './helpers/app'

async function mountSwitch(modelValue: boolean, props: { id?: string } = {}) {
  const { wrapper } = await mountWithApp(ToggleSwitch, {
    props: {
      modelValue,
      ...props,
      'onUpdate:modelValue': (value: boolean): void => {
        void wrapper.setProps({ modelValue: value })
      },
    },
    slots: { default: 'Hide my display name' },
  })
  return { wrapper, control: wrapper.get('[role="switch"]') }
}

describe('ToggleSwitch', () => {
  afterEach(cleanup)

  it('is a switch named by its label, showing the bound state', async () => {
    const { wrapper, control } = await mountSwitch(true)

    const label = wrapper.get('label')
    expect(label.text()).toBe('Hide my display name')
    expect(label.attributes('for')).toBe(control.attributes('id'))
    expect(control.attributes('aria-checked')).toBe('true')
  })

  it('flips the model when clicked', async () => {
    const { wrapper, control } = await mountSwitch(false)

    await control.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([[true]])
    expect(control.attributes('aria-checked')).toBe('true')

    await control.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([[true], [false]])
  })

  it('flips from its label too', async () => {
    const { wrapper } = await mountSwitch(false)

    await wrapper.get('label').trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([[true]])
  })

  it('takes a given id, so a label elsewhere can point at it', async () => {
    const { control } = await mountSwitch(false, { id: 'notify-me' })

    expect(control.attributes('id')).toBe('notify-me')
  })

  it('renders no label when given no text', async () => {
    const { wrapper } = await mountWithApp(ToggleSwitch, { props: { modelValue: false } })

    expect(wrapper.find('label').exists()).toBe(false)
  })
})
