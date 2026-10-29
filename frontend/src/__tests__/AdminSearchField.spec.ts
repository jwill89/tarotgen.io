import { describe, it, expect, afterEach } from 'vitest'
import AdminSearchField from '@/components/admin/AdminSearchField.vue'
import { mountWithApp, cleanup } from './helpers/app'

async function mountSearch(label?: string) {
  const { wrapper } = await mountWithApp(AdminSearchField, {
    props: { modelValue: 'tarot', placeholder: 'Search decks…', label },
  })
  return { wrapper, input: wrapper.get('input') }
}

describe('AdminSearchField', () => {
  afterEach(cleanup)

  it('shows the current search and reports what is typed', async () => {
    const { wrapper, input } = await mountSearch()

    expect((input.element as HTMLInputElement).value).toBe('tarot')
    await input.setValue('thoth')

    expect(wrapper.emitted('update:modelValue')).toEqual([['thoth']])
  })

  it('is named by its placeholder when it has no visible label', async () => {
    const { wrapper, input } = await mountSearch()

    expect(wrapper.find('label').exists()).toBe(false)
    expect(input.attributes('aria-label')).toBe('Search decks…')
  })

  it('is named by its visible label when it has one', async () => {
    const { wrapper, input } = await mountSearch('Search')

    const label = wrapper.get('label')
    expect(label.text()).toBe('Search')
    expect(label.attributes('for')).toBe(input.attributes('id'))
    expect(input.attributes('aria-label')).toBeUndefined()
  })
})
