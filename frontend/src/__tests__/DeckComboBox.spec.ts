import { describe, it, expect, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import DeckComboBox from '@/components/DeckComboBox.vue'
import { mountWithApp, cleanup } from './helpers/app'
import type { Deck } from '@/types'

const deck = (deck_id: number, name: string, artist: string, system = 'RWS'): Deck =>
  ({ deck_id, name, artist, system_short_name: system, additional_cards: 0 }) as Deck

const decks = [
  deck(1, 'Rider-Waite Tarot', 'Pamela Colman Smith'),
  deck(2, 'Celestial Tarot', 'Kay Steventon'),
  deck(3, 'Thoth Tarot', 'Lady Frieda Harris', 'Thoth'),
]

const options = () =>
  [...document.body.querySelectorAll('[role="option"]')].map(
    (o) => o.querySelector('.myst-combobox__item-name')?.textContent,
  )

async function mountPicker(modelValue: number | null = 1) {
  const { wrapper } = await mountWithApp(DeckComboBox, {
    props: { decks, modelValue, id: 'deck-picker' },
  })
  return { wrapper, input: wrapper.get('input') }
}

describe('DeckComboBox', () => {
  afterEach(cleanup)

  it("shows the selected deck's name and puts the id on the input", async () => {
    const { input } = await mountPicker()

    expect((input.element as HTMLInputElement).value).toBe('Rider-Waite Tarot')
    expect(input.attributes('id')).toBe('deck-picker')
  })

  it('filters by name, artist or system as the user types', async () => {
    const { input } = await mountPicker()

    await input.setValue('celes')
    await flushPromises()
    expect(options()).toEqual(['Celestial Tarot'])

    await input.setValue('harris')
    await flushPromises()
    expect(options()).toEqual(['Thoth Tarot'])

    await input.setValue('thoth')
    await flushPromises()
    expect(options()).toEqual(['Thoth Tarot'])
  })

  it('says so when nothing matches', async () => {
    const { input } = await mountPicker()

    await input.setValue('zzz')
    await flushPromises()

    expect(options()).toEqual([])
    expect(document.body.textContent).toContain('No decks found.')
  })

  it('picks the first match with Enter and reports its id', async () => {
    const { wrapper, input } = await mountPicker()

    await input.setValue('celes')
    await flushPromises()
    await input.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([2])
  })

  it('groups the list by deck system', async () => {
    const { wrapper } = await mountPicker()

    await wrapper.get('button[aria-label="Toggle decks"]').trigger('click')
    await flushPromises()

    const groups = [...document.body.querySelectorAll('.myst-combobox__group')].map(
      (g) => g.textContent,
    )
    expect(groups).toEqual(['RWS', 'Thoth'])
    expect(options()).toEqual(['Rider-Waite Tarot', 'Celestial Tarot', 'Thoth Tarot'])
  })
})
