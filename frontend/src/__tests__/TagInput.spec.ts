import { describe, it, expect, afterEach } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import TagInput from '@/components/TagInput.vue'
import { mountWithApp, cleanup } from './helpers/app'
import type { ReadingTag } from '@/types'

/** A TagInput bound to a list the test can read, as a page binds one. */
async function mountTags(initial: string[] = [], suggestions: ReadingTag[] = []) {
  const tags = ref<string[]>(initial)
  const wanted = ref(0)
  const Host = defineComponent({
    render: () =>
      h(TagInput, {
        id: 'tags',
        modelValue: tags.value,
        'onUpdate:modelValue': (value: string[]) => {
          tags.value = value
        },
        suggestions,
        onWantSuggestions: () => {
          wanted.value++
        },
      }),
  })
  const { wrapper } = await mountWithApp(Host)
  return { wrapper, tags, wanted, input: wrapper.get('#tags') }
}

async function type(wrapper: VueWrapper, text: string, key = 'Enter') {
  const input = wrapper.get('#tags')
  await input.setValue(text)
  await input.trigger('keydown', { key })
  await flushPromises()
}

const chips = (wrapper: VueWrapper) => wrapper.findAll('.tag-input__chip').map((c) => c.text())
const offered = (wrapper: VueWrapper) =>
  wrapper.findAll('.tag-input__suggestion').map((b) => b.text())

describe('TagInput', () => {
  afterEach(cleanup)

  it('adds a tag on Enter, stored as the server stores it', async () => {
    const { wrapper, tags } = await mountTags()

    await type(wrapper, '  #New   Moon ')

    expect(tags.value).toEqual(['new moon'])
    expect(chips(wrapper)).toEqual(['new moon'])
  })

  it('adds a tag at a comma, as the reader types it', async () => {
    const { tags, input } = await mountTags()

    const el = input.element as HTMLInputElement
    el.value = 'career,'
    el.dispatchEvent(new InputEvent('input', { data: ',' }))
    await flushPromises()

    expect(tags.value).toEqual(['career'])
    expect(el.value).toBe('')
  })

  it('splits a pasted list into tags', async () => {
    const { tags, input } = await mountTags()

    const paste = new Event('paste', { bubbles: true, cancelable: true }) as Event & {
      clipboardData: { getData: () => string }
    }
    paste.clipboardData = { getData: () => 'Love,career,LOVE' }
    input.element.dispatchEvent(paste)
    await flushPromises()

    expect(tags.value).toEqual(['love', 'career'])
  })

  it('adds what was left typed when the field loses focus, split at its commas', async () => {
    const { tags, input } = await mountTags(['career'])

    // Typed (or autofilled) in one go, so no single keystroke was the comma.
    await input.setValue('#Monthly, love,')
    await input.trigger('blur')
    await flushPromises()

    expect(tags.value).toEqual(['career', 'monthly', 'love'])
  })

  it('keeps one of a tag however it is written, and nothing for a bare #', async () => {
    const { wrapper, tags } = await mountTags(['career'])

    await type(wrapper, 'CAREER')
    await type(wrapper, '#')

    expect(tags.value).toEqual(['career'])
  })

  it('removes a tag with its ×', async () => {
    const { wrapper, tags } = await mountTags(['career', 'love'])

    await wrapper.findAll('.tag-input__remove')[0]?.trigger('click')

    expect(tags.value).toEqual(['love'])
  })

  it('names the text field by the id it is given', async () => {
    const { input } = await mountTags()

    expect(input.element.tagName).toBe('INPUT')
    expect(input.attributes('maxlength')).toBe('30')
  })

  it('stops at ten tags', async () => {
    const ten = Array.from({ length: 10 }, (_, i) => `tag ${String(i + 1)}`)
    const { wrapper, tags, input } = await mountTags(ten, [{ tag: 'more', count: 1 }])

    await type(wrapper, 'eleven')

    expect(tags.value).toEqual(ten)
    expect(input.attributes('placeholder')).toBe('Ten tags at most')
    expect(offered(wrapper)).toEqual([])
  })

  it('asks for suggestions when the field is focused', async () => {
    const { wanted, input } = await mountTags()

    await input.trigger('focus')

    expect(wanted.value).toBe(1)
  })

  it('offers the most used of the tags not chosen yet, narrowed by what is typed', async () => {
    const suggestions = [
      { tag: 'career', count: 2 },
      { tag: 'carry on', count: 2 },
      { tag: 'love', count: 9 },
      { tag: 'monthly', count: 4 },
      { tag: 'a', count: 1 },
      { tag: 'b', count: 1 },
      { tag: 'c', count: 1 },
    ]
    const { wrapper, tags, input } = await mountTags(['monthly'], suggestions)

    // Six at most, most used first, ties alphabetically.
    expect(offered(wrapper)).toEqual(['love', 'career', 'carry on', 'a', 'b', 'c'])

    await input.setValue('CAR')
    expect(offered(wrapper)).toEqual(['career', 'carry on'])

    await wrapper.get('[aria-label="Add tag carry on"]').trigger('click')
    expect(tags.value).toEqual(['monthly', 'carry on'])
    expect(offered(wrapper)).toEqual(['love', 'career', 'a', 'b', 'c'])
  })
})
