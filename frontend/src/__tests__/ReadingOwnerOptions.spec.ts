import { describe, it, expect, afterEach, beforeEach } from 'vitest'
import { h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import ReadingOwnerOptions from '@/components/ReadingOwnerOptions.vue'
import { mountWithApp, cleanup, signIn, testUser } from './helpers/app'

async function mountOptions(notes = false) {
  // The notes editor's toolbar tooltips need the provider App.vue mounts.
  const { wrapper, router } = await mountWithApp(TooltipProvider, {
    slots: { default: () => h(ReadingOwnerOptions, { notes }) },
  })
  return { wrapper, router, options: wrapper.getComponent(ReadingOwnerOptions) }
}

describe('ReadingOwnerOptions', () => {
  beforeEach(() => {
    signIn(testUser())
  })
  afterEach(cleanup)

  it('invites a guest to log in instead, and has nothing to collect from them', async () => {
    signIn(null)
    const { wrapper, router, options } = await mountOptions()

    expect(wrapper.find('input').exists()).toBe(false)
    const login = wrapper.get('a')
    expect(login.text()).toBe('Log in')
    expect(login.attributes('href')).toBe(router.resolve({ name: 'login' }).href)
    expect(options.vm.collect()).toBeNull()
  })

  it('collects the title (trimmed), hidden name and password a signed-in user sets', async () => {
    const { wrapper, options } = await mountOptions()

    await wrapper.get('#ro-name').setValue('  Morning reflection  ')
    await wrapper.get('[role="switch"]').trigger('click')
    await wrapper.get('#ro-password').setValue('s3cret')

    expect(options.vm.collect()).toEqual({
      reading_name: 'Morning reflection',
      hide_user: true,
      password: 's3cret',
      reading_notes: '',
    })
  })

  it('names each field for assistive tech', async () => {
    const { wrapper } = await mountOptions()

    expect(wrapper.get('label[for="ro-name"]').text()).toContain('Reading Title')
    expect(wrapper.get('label[for="ro-password"]').text()).toContain('View Password')
    const hide = wrapper.get('[role="switch"]')
    expect(wrapper.get(`label[for="${hide.attributes('id')}"]`).text()).toBe(
      'Hide my display name on this reading',
    )
  })

  it('hides the password as it is typed, with a switch to show it', async () => {
    const { wrapper } = await mountOptions()
    const password = wrapper.get('#ro-password')

    expect(password.attributes('type')).toBe('password')
    expect(wrapper.findAll('[role="switch"]')).toHaveLength(1)

    await password.setValue('s3cret')
    const show = wrapper.findAll('[role="switch"]').at(1)
    expect(wrapper.get(`label[for="${show?.attributes('id')}"]`).text()).toBe('Show password')
    await show?.trigger('click')

    expect(password.attributes('type')).toBe('text')
  })

  it('offers reading notes only where the page asks for them', async () => {
    const without = await mountOptions(false)
    expect(without.wrapper.find('[role="textbox"]').exists()).toBe(false)

    const withNotes = await mountOptions(true)
    const editor = withNotes.wrapper.get('[role="textbox"]')
    const captionId = editor.attributes('aria-labelledby') ?? ''
    expect(document.getElementById(captionId)?.textContent).toContain('Reading Notes')
  })

  it('collects the notes as markdown when the page asks for them', async () => {
    const { wrapper, options } = await mountOptions(true)
    const editor = wrapper.get('[role="textbox"]').element as HTMLElement

    // ProseMirror reads typing back from the DOM it watches.
    const paragraph = editor.querySelector('p')
    if (!paragraph) throw new Error('No paragraph to type into')
    paragraph.textContent = 'A gentle start'
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(options.vm.collect()?.reading_notes).toBe('A gentle start')
  })

  it('clears everything on reset', async () => {
    const { wrapper, options } = await mountOptions()
    await wrapper.get('#ro-name').setValue('Evening')
    await wrapper.get('[role="switch"]').trigger('click')
    await wrapper.get('#ro-password').setValue('s3cret')

    options.vm.reset()
    await flushPromises()

    expect(options.vm.collect()).toEqual({
      reading_name: '',
      hide_user: false,
      password: '',
      reading_notes: '',
    })
    expect((wrapper.get('#ro-name').element as HTMLInputElement).value).toBe('')
    expect(wrapper.findAll('[role="switch"]')).toHaveLength(1)
  })
})
