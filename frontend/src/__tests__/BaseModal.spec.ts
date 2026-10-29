import { describe, it, expect, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import BaseModal from '@/components/BaseModal.vue'
import { mountWithApp, cleanup } from './helpers/app'

async function mountModal(props: Record<string, unknown> = {}, footer = true) {
  const { wrapper } = await mountWithApp(BaseModal, {
    props: { active: true, title: 'Rename passkey', ...props },
    slots: {
      default: '<label for="m-name">Name</label><input id="m-name" />',
      ...(footer ? { footer: '<button type="button">Save</button>' } : {}),
    },
  })
  return wrapper
}

const dialog = () => document.body.querySelector<HTMLElement>('[role="dialog"]')

async function clickBackdrop(): Promise<void> {
  // Reka starts listening for outside pointerdowns a macrotask after opening.
  await new Promise((resolve) => setTimeout(resolve, 0))
  document.body
    .querySelector('.myst-modal-overlay')
    ?.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
  await flushPromises()
}

describe('BaseModal', () => {
  afterEach(cleanup)

  it('renders nothing while inactive', async () => {
    await mountModal({ active: false })

    expect(dialog()).toBeNull()
  })

  it('opens a dialog named by its title, holding its content and footer', async () => {
    await mountModal()

    const titleId = dialog()?.getAttribute('aria-labelledby') ?? ''
    expect(document.getElementById(titleId)?.textContent).toBe('Rename passkey')
    expect(dialog()?.querySelector('input#m-name')).not.toBeNull()
    expect(dialog()?.querySelector('footer')?.textContent).toBe('Save')
    expect(dialog()?.style.maxWidth).toBe('30rem')
  })

  it('leaves the footer out when none is given', async () => {
    await mountModal({}, false)

    expect(dialog()?.querySelector('footer')).toBeNull()
  })

  it('moves focus into the dialog when it opens', async () => {
    await mountModal()

    expect(dialog()?.contains(document.activeElement)).toBe(true)
  })

  it('asks to close from the close button, Escape and the backdrop', async () => {
    const modal = await mountModal()

    dialog()?.querySelector<HTMLButtonElement>('button[aria-label="Close"]')?.click()
    await flushPromises()
    expect(modal.emitted('close')).toHaveLength(1)

    await modal.setProps({ active: false })
    await modal.setProps({ active: true })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(modal.emitted('close')).toHaveLength(2)

    await modal.setProps({ active: false })
    await modal.setProps({ active: true })
    await clickBackdrop()
    expect(modal.emitted('close')).toHaveLength(3)
  })

  it('stays open on a backdrop click when told to', async () => {
    const modal = await mountModal({ closeOnBackdrop: false })

    await clickBackdrop()

    expect(modal.emitted('close')).toBeUndefined()
    expect(dialog()).not.toBeNull()
  })

  it('takes a custom width', async () => {
    await mountModal({ maxWidth: '44rem' })

    expect(dialog()?.style.maxWidth).toBe('44rem')
  })
})
