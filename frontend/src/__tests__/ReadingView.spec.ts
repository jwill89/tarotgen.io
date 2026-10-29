import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { h, ref } from 'vue'
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import ReadingView from '@/views/ReadingView.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { useConfirm } from '@/composables/useConfirm'
import { useDecks } from '@/composables/useDecks'
import { useRecentReadings } from '@/composables/useRecentReadings'
import { useToasts } from '@/composables/useToasts'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { formatDateTime } from '@/utils/datetime'
import { cardBackImageUrls, cardImageUrls } from '@/utils/cardImages'
import { mountWithApp, cleanup, signIn, stubApi, testRouter, testUser } from './helpers/app'
import type { Deck, LockedReading, Reading, ReadingInfo } from '@/types'

// Drawing the export needs a 2D canvas, which happy-dom lacks; the export itself
// is useReadingExport's to test. Here: what the page asks it to draw.
const exportReading = vi.fn<(options: unknown) => Promise<void>>(async () => {})
vi.mock('@/composables/useReadingExport', () => ({
  useReadingExport: () => ({ exporting: ref(false), exportReading }),
}))

const rws: Deck = {
  deck_id: 1,
  deck_system_id: 1,
  name: 'Rider-Waite Tarot',
  artist: 'Pamela Colman Smith',
  purchase_url: 'https://shop.example/rws',
  additional_cards: 2,
  card_aspect_w: 5,
  card_aspect_h: 8.6,
  approved: true,
  usable: true,
  system_short_name: 'RWS',
  system_total_cards: 78,
}

const freeDrawInfo: ReadingInfo = {
  deck_id: 1,
  origin: 'generated',
  draw: [
    { card_id: 2, reversed: false, card_name: 'The Magician' },
    { card_id: 79, reversed: true },
  ],
}

const spreadInfo: ReadingInfo = {
  deck_id: 1,
  origin: 'generated',
  spread: {
    spread_id: 3,
    name: 'Past, Present, Future',
    description: 'A **classic** three-card line.',
    // Out of order on purpose: positions pair with cards by `order`.
    positions: [
      { order: 2, title: 'Present', x: 50, y: 50, rotation: 0 },
      { order: 1, title: 'Past', x: 25, y: 50, rotation: 0 },
      { order: 3, title: 'Future', x: 75, y: 50, rotation: 0 },
    ],
  },
  draw: [
    { card_id: 17, reversed: false, card_name: 'The Star' },
    { card_id: 18, reversed: true, card_name: 'The Moon' },
    { card_id: 19, reversed: false, card_name: 'The Sun' },
  ],
}

function reading(overrides: Partial<Reading> = {}): Reading {
  return {
    reading_id: 'abc123',
    reading_info: freeDrawInfo,
    reading_time: '2026-10-01 12:30:00',
    reading_name: null,
    reading_notes: null,
    reader: 'Guest',
    is_owner: false,
    is_final: false,
    can_draw_more: false,
    locked: false,
    ...overrides,
  }
}

const locked: LockedReading = { locked: true, reading_name: 'My secret reading' }

/** The calls the page makes on load: the decks, and the reading answered with `body`. */
function loads(body: Reading | LockedReading, extra: Parameters<typeof stubApi>[0] = {}) {
  return stubApi({
    'GET /decks': { body: [rws] },
    'GET /readings/abc123': { body },
    ...extra,
  })
}

/**
 * The page as App.vue hosts it: under the tooltip provider, beside the one
 * ConfirmDialog that "Mark as Final" asks through.
 */
async function openReading(path = '/reading/abc123', router = testRouter()) {
  const { wrapper } = await mountWithApp(
    { render: () => h(TooltipProvider, null, () => [h(ReadingView), h(ConfirmDialog)]) },
    { path, router },
  )
  return { wrapper, router, page: wrapper.getComponent(ReadingView) }
}

const button = (name: string) => {
  const found = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find(
    (b) => b.textContent.trim() === name || b.getAttribute('aria-label') === name,
  )
  if (!found) throw new Error(`No "${name}" button`)
  return found
}
const hasButton = (name: string) =>
  [...document.body.querySelectorAll('button')].some((b) => b.textContent.trim() === name)

async function click(name: string): Promise<void> {
  button(name).click()
  await flushPromises()
}

/** An element's text as it reads: each piece of text, space-separated. */
function words(el: Element): string {
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT)
  const parts: string[] = []
  while (walker.nextNode()) {
    const text = walker.currentNode.textContent?.trim()
    if (text) parts.push(text)
  }
  return parts.join(' ')
}

/** The meta list as label → value. */
function meta(wrapper: VueWrapper): Record<string, string> {
  return Object.fromEntries(
    wrapper.findAll('.meta-row').map((row) => [row.get('dt').text(), words(row.get('dd').element)]),
  )
}

/** The image-size choice whose heading is `size`. */
function exportOption(size: string): HTMLButtonElement {
  const found = [...document.body.querySelectorAll<HTMLButtonElement>('.export-option')].find(
    (b) => b.querySelector('strong')?.textContent === size,
  )
  if (!found) throw new Error(`No "${size}" option`)
  return found
}

const cardButtons = (wrapper: VueWrapper) => wrapper.findAll('.spread-flip')
const captions = (wrapper: VueWrapper) =>
  wrapper.findAll('.reading-grid-caption').map((c) => c.text())
const detailRows = (wrapper: VueWrapper) =>
  wrapper.findAll('.spread-detail-text').map((r) => words(r.element))
const toastMessages = () => useToasts().toasts.value.map((t) => [t.type, t.message])
const lightbox = () =>
  [...document.body.querySelectorAll('[role="dialog"]')].find((d) =>
    d.classList.contains('lightbox-content'),
  )

describe('ReadingView', () => {
  beforeEach(() => {
    // Module state the page shares with the rest of the app.
    useDecks().decks.value = []
    useDecks().deckLookup.value = {}
    useToasts().toasts.value = []
    useRecentReadings().clear()
    useConfirm().settle(false)
    signIn(null)
    exportReading.mockClear()
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('viewing a reading', () => {
    it('describes the reading: its link, date, spread, reader and deck', async () => {
      loads(reading())
      const { wrapper } = await openReading()

      expect(wrapper.get('h1').text()).toBe('Your Reading')
      expect(meta(wrapper)).toEqual({
        'Reading ID': 'abc123',
        'Reading URL': 'https://tarotgen.io/reading/abc123 Copy',
        Date: formatDateTime('2026-10-01 12:30:00'),
        Spread: 'Free Draw',
        Reader: 'Guest',
        Deck: 'Rider-Waite Tarot · art by Pamela Colman Smith RWS',
        Purchase: 'Buy this deck',
      })
      const buy = wrapper.get('a[href="https://shop.example/rws"]')
      expect(buy.attributes('target')).toBe('_blank')
      expect(buy.attributes('rel')).toContain('noopener')
    })

    it("uses the owner's title for the reading when it has one", async () => {
      loads(reading({ reading_name: 'Morning reflection', reader: 'Reader' }))
      const { wrapper } = await openReading()

      expect(wrapper.get('h1').text()).toBe('Morning reflection')
      expect(meta(wrapper).Reader).toBe('Reader')
    })

    it('remembers the reading among the recent ones', async () => {
      loads(reading())
      await openReading()

      expect(useRecentReadings().recent.value).toEqual([
        {
          id: 'abc123',
          deckName: 'Rider-Waite Tarot',
          summary: '2 cards',
          at: '2026-10-01 12:30:00',
        },
      ])
    })

    it('copies the reading link', async () => {
      loads(reading())
      const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue()
      await openReading()

      await click('Copy')

      expect(writeText).toHaveBeenCalledWith('https://tarotgen.io/reading/abc123')
      expect(toastMessages()).toEqual([['success', 'Reading link copied to clipboard.']])
    })

    it('shows a loading overlay until the reading arrives', async () => {
      let answer: (reply: { body: Reading }) => void = () => {}
      const pending = new Promise<{ body: Reading }>((resolve) => (answer = resolve))
      const api = stubApi({ 'GET /decks': { body: [rws] } })
      api.mockImplementation(async (input: string) => {
        if (input.endsWith('/decks')) return Response.json([rws])
        return Response.json((await pending).body)
      })
      const { wrapper } = await openReading()

      expect(wrapper.text()).toContain('Loading your reading...')
      answer({ body: reading() })
      await flushPromises()
      expect(wrapper.text()).not.toContain('Loading your reading...')
      expect(wrapper.get('h1').text()).toBe('Your Reading')
    })
  })

  describe('a free draw', () => {
    it('deals the cards face down, numbered', async () => {
      loads(reading())
      const { wrapper } = await openReading()

      expect(captions(wrapper)).toEqual(['Card 1', 'Card 2'])
      expect(wrapper.findAll('.spread-face--back img').map((i) => i.attributes('src'))).toEqual([
        cardBackImageUrls(1).thumb,
        cardBackImageUrls(1).thumb,
      ])
    })

    it('reveals a card when it is clicked, naming unnamed cards by number', async () => {
      loads(reading())
      const { wrapper } = await openReading()

      await cardButtons(wrapper)[1].trigger('click')

      expect(captions(wrapper)).toEqual(['Card 1', 'Card #79 (Reversed)'])
      expect(wrapper.findAll('.spread-face--reveal img')[1].attributes('src')).toBe(
        cardImageUrls(1, 79).thumb,
      )
    })

    it('reveals a card from the keyboard with Enter or Space', async () => {
      loads(reading())
      const { wrapper } = await openReading()

      expect(cardButtons(wrapper)[0].attributes('tabindex')).toBe('0')
      await cardButtons(wrapper)[0].trigger('keydown', { key: 'Enter' })
      await cardButtons(wrapper)[1].trigger('keydown', { key: ' ' })

      expect(captions(wrapper)).toEqual(['The Magician', 'Card #79 (Reversed)'])
    })

    it('enlarges a revealed card in the lightbox, which Escape closes back to the card', async () => {
      loads(reading())
      const { wrapper } = await openReading()
      const card = cardButtons(wrapper)[0]

      await card.trigger('click')
      expect(lightbox()).toBeUndefined()
      ;(card.element as HTMLElement).focus()
      await card.trigger('click')
      await flushPromises()

      expect(lightbox()?.querySelector('img')?.getAttribute('src')).toBe(
        cardImageUrls(1, 2).display,
      )
      expect(lightbox()?.textContent).toContain('The Magician')

      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
      await flushPromises()
      expect(lightbox()).toBeUndefined()
      // Back on the card it was opened from, so keyboard users keep their place.
      await vi.waitFor(() => expect(document.activeElement).toBe(card.element))
    })

    it('flips every card over in turn, then back again', async () => {
      vi.useFakeTimers()
      try {
        loads(reading())
        const { wrapper } = await openReading()

        await click('Flip All')
        // Turned one after another, a beat apart.
        expect(captions(wrapper)).toEqual(['The Magician', 'Card 2'])
        await vi.advanceTimersByTimeAsync(600)
        expect(captions(wrapper)).toEqual(['The Magician', 'Card #79 (Reversed)'])

        await click('Flip All Back')
        expect(captions(wrapper)).toEqual(['Card 1', 'Card 2'])
      } finally {
        vi.useRealTimers()
      }
    })
  })

  describe('a spread', () => {
    it('lays the cards out on the spread, face down, with their positions listed', async () => {
      loads(reading({ reading_info: spreadInfo }))
      const { wrapper } = await openReading()

      expect(meta(wrapper).Spread).toBe('Past, Present, Future')
      expect(wrapper.findAll('.spread-card')).toHaveLength(3)
      expect(detailRows(wrapper)).toEqual([
        'Past Not yet revealed',
        'Present Not yet revealed',
        'Future Not yet revealed',
      ])
    })

    it('reveals a card clicked on the spread, then enlarges it', async () => {
      loads(reading({ reading_info: spreadInfo }))
      const { wrapper } = await openReading()
      const present = wrapper.findAll('.spread-card .spread-flip')[1]

      await present.trigger('click')
      expect(detailRows(wrapper)[1]).toBe('Present The Moon (Reversed)')

      await present.trigger('click')
      await flushPromises()
      expect(lightbox()?.textContent).toContain('The Moon')
    })

    // The highlight is the link between a card and its row, so the class is the behaviour.
    it('highlights the row of the card under the pointer or focus, and the card of the row', async () => {
      loads(reading({ reading_info: spreadInfo }))
      const { wrapper } = await openReading()
      const active = () => ({
        card: wrapper.findAll('.spread-card').findIndex((c) => c.classes('is-active')),
        row: wrapper.findAll('.spread-detail-row').findIndex((r) => r.classes('is-active')),
      })

      await wrapper.findAll('.spread-card')[2].trigger('mouseenter')
      expect(active()).toEqual({ card: 2, row: 2 })

      await wrapper.findAll('.spread-card')[2].trigger('mouseleave')
      expect(active()).toEqual({ card: -1, row: -1 })

      await wrapper.findAll('.spread-card .spread-flip')[0].trigger('focusin')
      expect(active()).toEqual({ card: 0, row: 0 })

      await wrapper.findAll('.spread-detail-row')[1].trigger('mouseenter')
      expect(active()).toEqual({ card: 1, row: 1 })
    })

    it('reveals a card from its row in the details list', async () => {
      loads(reading({ reading_info: spreadInfo }))
      const { wrapper } = await openReading()

      await wrapper.findAll('.spread-detail-row')[1].trigger('click')

      expect(detailRows(wrapper)[1]).toBe('Present The Moon (Reversed)')
    })

    it('explains the spread in a collapsible section, closed at first', async () => {
      loads(reading({ reading_info: spreadInfo }))
      const { wrapper } = await openReading()

      const toggle = wrapper.get('.spread-details-header')
      expect(toggle.text()).toBe('Spread Details: Past, Present, Future')
      expect(toggle.attributes('aria-expanded')).toBe('false')

      await toggle.trigger('click')
      expect(toggle.attributes('aria-expanded')).toBe('true')
      expect(wrapper.get('.spread-details .content strong').text()).toBe('classic')
    })

    it('summarises the reading by its spread among the recent ones', async () => {
      loads(reading({ reading_info: spreadInfo }))
      await openReading()

      expect(useRecentReadings().recent.value[0].summary).toBe('Past, Present, Future')
    })

    it('asks the owner to place newly drawn cards that have no position yet', async () => {
      signIn(testUser())
      const fourCards: ReadingInfo = {
        ...spreadInfo,
        draw: [...spreadInfo.draw, { card_id: 20, reversed: false, card_name: 'Judgement' }],
      }
      loads(reading({ reading_info: fourCards, is_owner: true, can_draw_more: true }))
      const { wrapper, router } = await openReading()

      const callout = wrapper.get('.myst-callout')
      expect(callout.text()).toContain('You have 1 newly drawn card waiting to be placed')
      expect(callout.get('a').attributes('href')).toBe(
        router.resolve({ name: 'free-draw-placement', params: { id: 'abc123' } }).href,
      )
    })
  })

  it("shows the owner's notes, open, as rendered markdown", async () => {
    loads(reading({ reading_notes: 'The **Tower** speaks of change.' }))
    const { wrapper } = await openReading()

    const notes = wrapper.get('.reading-notes')
    expect(notes.get('.spread-details-header').attributes('aria-expanded')).toBe('true')
    expect(notes.get('strong').text()).toBe('Tower')
  })

  it('folds the notes away and back', async () => {
    loads(reading({ reading_notes: 'The **Tower** speaks of change.' }))
    const { wrapper } = await openReading()
    const toggle = wrapper.get('.reading-notes .spread-details-header')

    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')

    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')
  })

  describe('a reading that cannot be shown', () => {
    it('says so when there is no reading with that code', async () => {
      loads(reading(), { 'GET /readings/abc123': { status: 404, body: { error: 'Not found' } } })
      const { wrapper, router } = await openReading()

      expect(wrapper.get('h1').text()).toBe('Reading not found')
      expect(toastMessages()).toEqual([['error', "We couldn't find a reading with that code."]])
      const links = wrapper.findAll('a').map((a) => [a.text(), a.attributes('href')])
      expect(links).toEqual([
        ['Start a New Draw', router.resolve({ name: 'new-reading' }).href],
        ['Back to Home', router.resolve({ name: 'home' }).href],
      ])
    })

    it("says what went wrong when it isn't a missing reading", async () => {
      loads(reading(), {
        'GET /readings/abc123': { status: 429, body: { error: 'Too many requests.' } },
      })
      await openReading()

      expect(toastMessages()).toEqual([['error', 'Too many requests.']])
    })

    it('says when the connection failed', async () => {
      const api = loads(reading())
      api.mockImplementation(async (input: string) => {
        if (input.endsWith('/decks')) return Response.json([rws])
        throw new TypeError('Failed to fetch')
      })
      await openReading()

      expect(toastMessages()).toEqual([['error', NETWORK_ERROR_MESSAGE]])
    })
  })

  describe('a password-protected reading', () => {
    const passwordBox = (wrapper: VueWrapper) => wrapper.get('input[type="password"]')

    it('asks for the password, showing only the reading title', async () => {
      loads(locked)
      const { wrapper } = await openReading()

      expect(wrapper.get('h1').text()).toBe('My secret reading')
      expect(wrapper.text()).toContain('This reading is password-protected.')
      expect(passwordBox(wrapper).attributes('aria-label')).toBe('Reading password')
      expect(wrapper.find('.meta-row').exists()).toBe(false)
    })

    it('calls an untitled one a protected reading', async () => {
      loads({ locked: true, reading_name: null })
      const { wrapper } = await openReading()

      expect(wrapper.get('h1').text()).toBe('Protected Reading')
    })

    it('cannot be submitted without a password', async () => {
      loads(locked)
      await openReading()

      expect(button('View Reading').disabled).toBe(true)
    })

    it('shows the reading once the right password is given', async () => {
      const api = loads(locked, {
        'POST /readings/abc123/unlock': { body: reading({ reading_name: 'My secret reading' }) },
      })
      const { wrapper } = await openReading()

      await passwordBox(wrapper).setValue('open sesame')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      const [, init] = api.mock.calls.at(-1) ?? []
      expect(JSON.parse(String(init?.body))).toEqual({ password: 'open sesame' })
      expect(meta(wrapper)['Reading ID']).toBe('abc123')
      expect(useRecentReadings().recent.value[0].summary).toBe('My secret reading')
    })

    it("shows the server's message for a wrong password, and lets the user retry", async () => {
      loads(locked, {
        'POST /readings/abc123/unlock': { status: 401, body: { error: 'Incorrect password.' } },
      })
      const { wrapper } = await openReading()

      await passwordBox(wrapper).setValue('guess')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(wrapper.get('.notification.is-danger').text()).toBe('Incorrect password.')
      expect(button('View Reading').disabled).toBe(false)
    })
  })

  describe('saving as an image', () => {
    it('offers a standard and a high-resolution image', async () => {
      loads(reading({ reading_info: spreadInfo }))
      await openReading()

      await click('Save as Image')
      const dialog = document.body.querySelector('[role="dialog"]')
      expect(dialog?.textContent).toContain('Save reading image')

      expect(exportOption('Standard').textContent).toContain('~1600px wide')
      expect(exportOption('High resolution').textContent).toContain('~3200px wide')
      exportOption('Standard').click()
      await flushPromises()

      expect(document.body.querySelector('[role="dialog"]')).toBeNull()
      expect(exportReading).toHaveBeenCalledWith({
        fileName: 'tarot-reading-abc123.png',
        readingId: 'abc123',
        title: 'Past, Present, Future',
        subtitle: 'Rider-Waite Tarot  ·  ' + formatDateTime('2026-10-01 12:30:00'),
        cards: spreadInfo.draw.map((c) => ({
          imgUrl: cardImageUrls(1, c.card_id).display,
          fallbackUrl: cardImageUrls(1, c.card_id).png,
          reversed: c.reversed,
          card_name: c.card_name,
        })),
        positions: spreadInfo.spread?.positions,
        cardAspect: 8.6 / 5,
        scale: 1,
      })
    })

    it('names the high-resolution file apart and draws it at twice the scale', async () => {
      loads(reading())
      await openReading()

      await click('Save as Image')
      exportOption('High resolution').click()
      await flushPromises()

      expect(exportReading).toHaveBeenCalledWith(
        expect.objectContaining({
          fileName: 'tarot-reading-abc123-hires.png',
          title: 'Tarot Reading',
          positions: null,
          scale: 2,
        }),
      )
    })
  })

  describe("the owner's actions", () => {
    const owned = (overrides: Partial<Reading> = {}) =>
      reading({ is_owner: true, can_draw_more: true, reader: 'Reader', ...overrides })

    it('are not offered to a guest, even on a reading the server says can grow', async () => {
      loads(reading({ can_draw_more: true }))
      await openReading()

      expect(hasButton('Draw More Cards')).toBe(false)
      expect(hasButton('Mark as Final')).toBe(false)
    })

    it('are offered to the signed-in owner', async () => {
      signIn(testUser())
      loads(owned())
      await openReading()

      expect(hasButton('Draw More Cards')).toBe(true)
      expect(hasButton('Mark as Final')).toBe(true)
    })

    it('are gone, with a Final tag shown, once the reading is final', async () => {
      signIn(testUser())
      loads(owned({ is_final: true, can_draw_more: false }))
      const { wrapper } = await openReading()

      expect(wrapper.get('.reading-final-tag').text()).toBe('Final')
      expect(hasButton('Draw More Cards')).toBe(false)
      expect(hasButton('Mark as Final')).toBe(false)
    })

    it('draws more cards into a free draw and shows them', async () => {
      signIn(testUser())
      const grown = owned({
        reading_info: {
          ...freeDrawInfo,
          draw: [
            ...freeDrawInfo.draw,
            { card_id: 10, reversed: false, card_name: 'Wheel of Fortune' },
          ],
        },
      })
      const api = loads(owned(), { 'POST /readings/abc123/draw': { body: grown } })
      const { wrapper } = await openReading()

      await click('Draw More Cards')
      const dialog = document.body.querySelector('[role="dialog"]')
      expect(dialog?.textContent).toContain('Draw additional cards from Rider-Waite Tarot')
      expect(dialog?.textContent).toContain('76 cards left in this deck.')
      await click('Draw')

      const [, init] = api.mock.calls.at(-1) ?? []
      expect(init?.method).toBe('POST')
      expect(Object.fromEntries(new URLSearchParams(String(init?.body)))).toEqual({
        count: '1',
        use_reversals: 'false',
        use_additional_cards: 'false',
      })
      expect(captions(wrapper)).toHaveLength(3)
      expect(toastMessages()).toEqual([['success', 'Added new cards to your reading.']])
      expect(document.body.querySelector('[role="dialog"]')).toBeNull()
    })

    it("sends the draw's settings: count, reversals and the deck's extra cards", async () => {
      signIn(testUser())
      const api = loads(owned(), { 'POST /readings/abc123/draw': { body: owned() } })
      await openReading()

      await click('Draw More Cards')
      const dialog = document.body.querySelector<HTMLElement>('[role="dialog"]')
      const count = dialog?.querySelector<HTMLInputElement>('#reading-number-of-cards')
      if (!count) throw new Error('No card count field')
      count.value = '3'
      count.dispatchEvent(new Event('input'))
      count.dispatchEvent(new Event('change'))
      count.dispatchEvent(new Event('blur'))
      await flushPromises()
      dialog?.querySelector<HTMLElement>('#reading-reversed-cards')?.click()
      dialog?.querySelector<HTMLElement>('#reading-include-extra-card')?.click()
      await flushPromises()
      // The extra cards join what is left to draw.
      expect(dialog?.textContent).toContain('78 cards left in this deck.')
      await click('Draw')

      const [, init] = api.mock.calls.at(-1) ?? []
      expect(Object.fromEntries(new URLSearchParams(String(init?.body)))).toEqual({
        count: '3',
        use_reversals: 'true',
        use_additional_cards: 'true',
      })
    })

    it('sends the owner of a spread on to place the new cards', async () => {
      signIn(testUser())
      loads(owned({ reading_info: spreadInfo }), {
        'POST /readings/abc123/draw': { body: owned({ reading_info: spreadInfo }) },
      })
      const { router } = await openReading()

      await click('Draw More Cards')
      expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain(
        "You'll then place them on your spread.",
      )
      await click('Draw')

      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('free-draw-placement'))
      expect(router.currentRoute.value.params.id).toBe('abc123')
      expect(toastMessages()).toEqual([['success', 'Cards drawn! Now place them on your spread.']])
    })

    it('closes the draw form on Cancel without drawing', async () => {
      signIn(testUser())
      const api = loads(owned())
      await openReading()

      await click('Draw More Cards')
      await click('Cancel')

      expect(document.body.querySelector('[role="dialog"]')).toBeNull()
      expect(api.mock.calls.map(([url]) => url)).not.toContain('/api/readings/abc123/draw')
    })

    it('closes the image choice without saving anything', async () => {
      loads(reading())
      await openReading()

      await click('Save as Image')
      await click('Close')

      expect(document.body.querySelector('[role="dialog"]')).toBeNull()
      expect(exportReading).not.toHaveBeenCalled()
    })

    it("keeps the draw form open and shows the server's error when the draw fails", async () => {
      signIn(testUser())
      loads(owned(), {
        'POST /readings/abc123/draw': { status: 409, body: { error: 'This reading is final.' } },
      })
      await openReading()

      await click('Draw More Cards')
      await click('Draw')

      expect(toastMessages()).toEqual([['error', 'This reading is final.']])
      expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain(
        'Draw More Cards',
      )
    })

    it('marks the reading final once the owner confirms', async () => {
      signIn(testUser())
      const api = loads(owned(), {
        'POST /readings/abc123/finalize': { body: owned({ is_final: true, can_draw_more: false }) },
      })
      const { wrapper } = await openReading()

      await click('Mark as Final')
      const prompt = document.body.querySelector('[role="alertdialog"]')
      expect(prompt?.textContent).toContain('Mark reading as final?')
      expect(prompt?.textContent).toContain('This cannot be undone.')
      await click('Mark Final')

      expect(api.mock.calls.at(-1)?.[0]).toBe('/api/readings/abc123/finalize')
      expect(wrapper.get('.reading-final-tag').text()).toBe('Final')
      expect(toastMessages()).toEqual([['success', 'Reading marked as final.']])
    })

    it('leaves the reading open when the owner thinks better of it', async () => {
      signIn(testUser())
      const api = loads(owned())
      await openReading()

      await click('Mark as Final')
      await click('Cancel')

      expect(api.mock.calls.map(([url]) => url)).not.toContain('/api/readings/abc123/finalize')
      expect(hasButton('Mark as Final')).toBe(true)
    })

    it("shows the server's error when the reading can't be finalized", async () => {
      signIn(testUser())
      loads(owned(), {
        'POST /readings/abc123/finalize': { status: 403, body: { error: 'Not your reading.' } },
      })
      await openReading()

      await click('Mark as Final')
      await click('Mark Final')

      expect(toastMessages()).toEqual([['error', 'Not your reading.']])
      expect(hasButton('Mark as Final')).toBe(true)
    })
  })

  it('loads the new reading, face down again, when the code in the address changes', async () => {
    const other = reading({
      reading_id: 'xyz789',
      reading_info: {
        ...freeDrawInfo,
        draw: [{ card_id: 5, reversed: false, card_name: 'The Hierophant' }],
      },
    })
    loads(reading(), { 'GET /readings/xyz789': { body: other } })
    const { wrapper, router } = await openReading()
    await cardButtons(wrapper)[0].trigger('click')

    await router.push('/reading/xyz789')
    await flushPromises()

    expect(meta(wrapper)['Reading ID']).toBe('xyz789')
    expect(captions(wrapper)).toEqual(['Card 1'])
  })
})
