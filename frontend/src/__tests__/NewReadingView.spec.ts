import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { h } from 'vue'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import NewReadingView from '@/views/NewReadingView.vue'
import { useDecks } from '@/composables/useDecks'
import { useSpreads } from '@/composables/useSpreads'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useFavoriteSpreads } from '@/composables/useFavoriteSpreads'
import { useRecentReadings } from '@/composables/useRecentReadings'
import { useToasts } from '@/composables/useToasts'
import { STORAGE_KEYS } from '@/constants'
import { mountWithApp, cleanup, signIn, stubApi, testUser } from './helpers/app'
import type { Deck, Reading, Spread, UserSpread } from '@/types'

function deck(deck_id: number, name: string, extra: Partial<Deck> = {}): Deck {
  return {
    deck_id,
    deck_system_id: 1,
    name,
    artist: 'Artist',
    purchase_url: '',
    additional_cards: 0,
    card_aspect_w: 5,
    card_aspect_h: 8.6,
    approved: true,
    usable: true,
    system_short_name: 'RWS',
    system_total_cards: 78,
    ...extra,
  }
}

const rws = deck(1, 'Rider-Waite Tarot')
const thoth = deck(2, 'Thoth Tarot', { additional_cards: 2, system_short_name: 'Thoth' })

const threeCard: Spread = {
  spread_id: 3,
  name: 'Past, Present, Future',
  description: 'A **classic** line.',
  card_count: 3,
  positions: [
    { order: 2, title: 'Present', x: 50, y: 50, rotation: 0 },
    { order: 1, title: 'Past', x: 25, y: 50, rotation: 0 },
    { order: 3, title: 'Future', x: 75, y: 50, rotation: 0 },
  ],
}

const myMorning: UserSpread = {
  user_spread_id: 9,
  user_id: 7,
  name: 'My Morning Draw',
  description: '',
  card_count: 1,
  positions: [{ order: 1, title: 'Today', x: 50, y: 50, rotation: 0 }],
  created_at: '2026-01-01 00:00:00',
  updated_at: '2026-01-01 00:00:00',
}

const drawn: Reading = {
  reading_id: 'new123',
  reading_info: { deck_id: 1, draw: [{ card_id: 1, reversed: false, card_name: 'The Fool' }] },
  reading_time: '2026-10-28 09:00:00',
  reading_name: null,
  reading_notes: null,
  reader: 'Guest',
  is_owner: false,
  is_final: false,
  can_draw_more: false,
  locked: false,
}

/** The lists the page loads, a signed-in user's included, plus `extra`. */
function loads(extra: Parameters<typeof stubApi>[0] = {}) {
  return stubApi({
    'GET /decks': { body: [thoth, rws] },
    'GET /spreads': { body: [threeCard] },
    'GET /account/spreads': { body: [myMorning] },
    'GET /account/favorites': { body: [] },
    'GET /account/favorite-decks': { body: [] },
    ...extra,
  })
}

async function openPage() {
  // The pickers' favourite stars sit in tooltips, under the provider App.vue mounts.
  const { wrapper, router } = await mountWithApp(
    { render: () => h(TooltipProvider, null, () => h(NewReadingView)) },
    { path: '/new' },
  )
  return { wrapper, router }
}

const input = (wrapper: VueWrapper, id: string) => wrapper.get<HTMLInputElement>(`#${id}`)
const help = (wrapper: VueWrapper) => wrapper.findAll('.help').map((p) => p.text())
const button = (wrapper: VueWrapper, name: string) => {
  const found = wrapper.findAll('button').find((b) => b.text() === name)
  if (!found) throw new Error(`No "${name}" button`)
  return found
}

/** Pick from a combobox by typing a name and pressing Enter, as a user can. */
async function pick(wrapper: VueWrapper, id: string, text: string): Promise<void> {
  const box = input(wrapper, id)
  await box.setValue(text)
  await flushPromises()
  await box.trigger('keydown', { key: 'Enter' })
  await flushPromises()
}

/** The form fields the page posted to /readings/generate. */
function sentFields(api: ReturnType<typeof stubApi>): Record<string, string> {
  const call = api.mock.calls.find(([url]) => url === '/api/readings/generate')
  return Object.fromEntries(new URLSearchParams(String(call?.[1]?.body)))
}

async function generate(wrapper: VueWrapper): Promise<void> {
  await button(wrapper, 'Generate New Draw').trigger('click')
  await flushPromises()
}

describe('NewReadingView', () => {
  beforeEach(() => {
    useDecks().decks.value = []
    useDecks().deckLookup.value = {}
    useSpreads().spreads.value = []
    useUserSpreads().userSpreads.value = []
    useFavoriteSpreads().favorites.value = []
    useToasts().toasts.value = []
    useRecentReadings().clear()
    localStorage.removeItem(STORAGE_KEYS.lastDeck)
    signIn(null)
  })
  afterEach(cleanup)

  it('starts on a free draw of one card from Rider-Waite', async () => {
    loads()
    const { wrapper } = await openPage()

    expect(input(wrapper, 'deck_id').element.value).toBe('Rider-Waite Tarot')
    expect(input(wrapper, 'new-reading-spread').element.value).toBe('No Spread (Free Draw)')
    expect(input(wrapper, 'new-reading-number-of-cards').element.value).toBe('1')
    expect(help(wrapper)).toContain('Up to 78 cards for this deck.')
    expect(wrapper.text()).toContain('Free Draw Mode')
  })

  it('starts on the deck the visitor chose last time', async () => {
    localStorage.setItem(STORAGE_KEYS.lastDeck, '2')
    loads()
    const { wrapper } = await openPage()

    expect(input(wrapper, 'deck_id').element.value).toBe('Thoth Tarot')
  })

  it('remembers the deck the visitor picks', async () => {
    loads()
    const { wrapper } = await openPage()

    await pick(wrapper, 'deck_id', 'thoth')

    expect(localStorage.getItem(STORAGE_KEYS.lastDeck)).toBe('2')
  })

  it("offers a deck's extra cards, raising how many can be drawn", async () => {
    loads()
    const { wrapper } = await openPage()
    await pick(wrapper, 'deck_id', 'thoth')

    expect(wrapper.text()).toContain('This deck has 2 extra cards.')
    await wrapper.get('.notification [role="switch"]').trigger('click')

    expect(help(wrapper)).toContain('Up to 80 cards for this deck.')
  })

  it('brings the count back within the deck when its extra cards are turned off', async () => {
    loads()
    const { wrapper } = await openPage()
    await pick(wrapper, 'deck_id', 'thoth')
    const extras = wrapper.get('.notification [role="switch"]')
    await extras.trigger('click')
    await input(wrapper, 'new-reading-number-of-cards').setValue('80')
    await input(wrapper, 'new-reading-number-of-cards').trigger('blur')
    await flushPromises()

    await extras.trigger('click')
    await flushPromises()

    expect(input(wrapper, 'new-reading-number-of-cards').element.value).toBe('78')
  })

  it('draws a free draw and opens the new reading', async () => {
    const api = loads({ 'POST /readings/generate': { body: drawn } })
    const { wrapper, router } = await openPage()

    await generate(wrapper)

    expect(sentFields(api)).toEqual({
      deck_id: '1',
      number_of_cards: '1',
      use_reversals: 'false',
      use_additional_cards: 'false',
    })
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('reading'))
    expect(router.currentRoute.value.params.id).toBe('new123')
    expect(useToasts().toasts.value.map((t) => t.message)).toEqual(['Your reading is ready!'])
    expect(useRecentReadings().recent.value).toEqual([
      { id: 'new123', deckName: 'Rider-Waite Tarot', summary: '1 card', at: '2026-10-28 09:00:00' },
    ])
  })

  it('sends the number of cards and the reversals setting', async () => {
    const api = loads({ 'POST /readings/generate': { body: drawn } })
    const { wrapper } = await openPage()

    await input(wrapper, 'new-reading-number-of-cards').setValue('5')
    await input(wrapper, 'new-reading-number-of-cards').trigger('blur')
    await wrapper.get('#new-reading-reversed-cards').trigger('click')
    await generate(wrapper)

    expect(sentFields(api)).toMatchObject({ number_of_cards: '5', use_reversals: 'true' })
    expect(useRecentReadings().recent.value[0].summary).toBe('5 cards')
  })

  it('sends a free draw "with placement" on to arrange the cards', async () => {
    loads({ 'POST /readings/generate': { body: drawn } })
    const { wrapper, router } = await openPage()

    await button(wrapper, 'With Placement').trigger('click')
    expect(help(wrapper)).toContain(
      "After drawing, you'll arrange the cards on a spread canvas and name each position.",
    )
    await generate(wrapper)

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('free-draw-placement'))
    expect(useToasts().toasts.value.map((t) => t.message)).toEqual([
      'Cards drawn! Now arrange your spread.',
    ])
  })

  it("lays out a chosen spread's description, preview and position titles", async () => {
    loads()
    const { wrapper } = await openPage()

    await pick(wrapper, 'new-reading-spread', 'past')

    expect(wrapper.get('.spread-description strong').text()).toBe('classic')
    expect(wrapper.findAll('.preview-number').map((n) => n.text())).toEqual(['1', '2', '3'])
    const titles = wrapper.findAll<HTMLInputElement>('[aria-label$=" title"]')
    expect(titles.map((t) => [t.attributes('aria-label'), t.attributes('placeholder')])).toEqual([
      ['Position 1 title', 'Past'],
      ['Position 2 title', 'Present'],
      ['Position 3 title', 'Future'],
    ])
    // The spread sets the count, and there's no free-draw mode to choose.
    expect(input(wrapper, 'new-reading-number-of-cards').element.value).toBe('3')
    expect(input(wrapper, 'new-reading-number-of-cards').attributes('disabled')).toBeDefined()
    expect(help(wrapper)).toContain('Set by the selected spread.')
    expect(wrapper.text()).not.toContain('Free Draw Mode')
  })

  it('draws into a public spread, with any position titles the user renamed', async () => {
    const api = loads({ 'POST /readings/generate': { body: drawn } })
    const { wrapper } = await openPage()
    await pick(wrapper, 'new-reading-spread', 'past')

    await wrapper.get('[aria-label="Position 2 title"]').setValue('Right now')
    await generate(wrapper)

    expect(sentFields(api)).toMatchObject({
      number_of_cards: '3',
      spread_id: '3',
      position_titles: JSON.stringify(['', 'Right now', '']),
    })
    expect(useRecentReadings().recent.value[0].summary).toBe('Past, Present, Future')
  })

  it("draws into a signed-in user's own spread by its id", async () => {
    signIn(testUser())
    const api = loads({ 'POST /readings/generate': { body: drawn } })
    const { wrapper } = await openPage()

    await pick(wrapper, 'new-reading-spread', 'morning')
    await generate(wrapper)

    const sent = sentFields(api)
    expect(sent).toMatchObject({ user_spread_id: '9', number_of_cards: '1' })
    expect(sent.spread_id).toBeUndefined()
  })

  it("sends a signed-in user's title, hidden name and password", async () => {
    signIn(testUser())
    const api = loads({ 'POST /readings/generate': { body: drawn } })
    const { wrapper } = await openPage()

    await wrapper.get('#ro-name').setValue('Morning reflection')
    await wrapper.get('#ro-password').setValue('s3cret')
    await generate(wrapper)

    expect(sentFields(api)).toMatchObject({
      reading_name: 'Morning reflection',
      hide_user: 'false',
      password: 's3cret',
    })
  })

  it("sends a guest's draw without any owner options", async () => {
    const api = loads({ 'POST /readings/generate': { body: drawn } })
    const { wrapper } = await openPage()

    expect(wrapper.text()).toContain('to name your reading, hide your display name')
    await generate(wrapper)

    expect(Object.keys(sentFields(api))).not.toContain('hide_user')
  })

  it("shows the server's error and stays on the form", async () => {
    loads({ 'POST /readings/generate': { status: 422, body: { error: 'Too many cards.' } } })
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const { wrapper, router } = await openPage()

    await generate(wrapper)

    expect(useToasts().toasts.value.map((t) => [t.type, t.message])).toEqual([
      ['error', 'Too many cards.'],
    ])
    expect(router.currentRoute.value.name).toBe('new-reading')
    expect(wrapper.text()).not.toContain('Drawing your cards...')
  })

  it('goes back to a free draw when the spread is cleared', async () => {
    loads()
    const { wrapper } = await openPage()
    await pick(wrapper, 'new-reading-spread', 'past')

    await wrapper.get('button[aria-label="Clear spread (free draw)"]').trigger('pointerdown')
    await flushPromises()

    expect(wrapper.find('[aria-label="Position 1 title"]').exists()).toBe(false)
    expect(input(wrapper, 'new-reading-number-of-cards').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).toContain('Free Draw Mode')
  })

  it('resets every choice, the owner options included', async () => {
    signIn(testUser())
    const api = loads({ 'POST /readings/generate': { body: drawn } })
    const { wrapper } = await openPage()
    await pick(wrapper, 'deck_id', 'thoth')
    await pick(wrapper, 'new-reading-spread', 'past')
    await wrapper.get('#ro-name').setValue('Morning reflection')
    await wrapper.get('#ro-password').setValue('s3cret')

    await button(wrapper, 'Reset').trigger('click')
    await flushPromises()

    expect(input(wrapper, 'deck_id').element.value).toBe('Rider-Waite Tarot')
    expect(input(wrapper, 'new-reading-spread').element.value).toBe('No Spread (Free Draw)')
    expect(input(wrapper, 'new-reading-number-of-cards').element.value).toBe('1')
    expect(input(wrapper, 'ro-name').element.value).toBe('')
    expect(input(wrapper, 'ro-password').element.value).toBe('')

    // A reading drawn now isn't password-protected by the form's old state.
    await generate(wrapper)
    const sent = sentFields(api)
    expect(sent.password).toBeUndefined()
    expect(sent.reading_name).toBeUndefined()
  })
})
