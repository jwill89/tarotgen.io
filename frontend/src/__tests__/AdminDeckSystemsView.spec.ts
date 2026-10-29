import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import DeckSystemsView from '@/views/admin/DeckSystemsView.vue'
import { useUser } from '@/composables/useUser'
import { cleanup, stubApi, signIn, testUser } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  confirmDialog,
  answerConfirm,
  toasts,
  row,
  tableRows,
  column,
  header,
  labelled,
  valueOf,
  typeInto,
  enterNumber,
  apiCalls,
  cardHeadings,
  nameCards,
} from './helpers/page'
import type { DeckSystem, DeckSystemCard, DeckSystemWithCards } from '@/types'

const system = (
  deck_system_id: number,
  name: string,
  short_name: string,
  overrides: Partial<DeckSystem> = {},
): DeckSystem => ({
  deck_system_id,
  name,
  short_name,
  total_cards: 78,
  approved: true,
  submitted_by: null,
  ...overrides,
})

const card = (deck_system_id: number, card_id: number, name: string): DeckSystemCard => ({
  deck_system_id,
  card_id,
  name,
  keywords: null,
  meaning: null,
  advice: null,
  reversed_keywords: null,
  reversed_meaning: null,
  reversed_advice: null,
})

const systems = [
  system(1, 'Rider-Waite-Smith', 'RWS'),
  system(2, 'Thoth', 'Thoth'),
  system(3, 'Lenormand', 'Len', { total_cards: 36 }),
]
const pending = [system(9, 'Sola Busca', 'SB', { approved: false, submitted_by: 7 })]

const lenormand: DeckSystemWithCards = {
  ...systems[2],
  total_cards: 2,
  cards: [card(3, 1, 'Rider'), card(3, 2, 'Clover')],
}

/** The calls the page makes on load, answered with these lists. */
const loads = (approved: DeckSystem[] = systems, waiting: DeckSystem[] = []) => ({
  'GET /admin/deck-systems': { body: approved },
  'GET /admin/deck-systems/pending': { body: waiting },
})

/** The panel holding the approved-systems table (pending submissions have their own). */
function approvedTable(): Element {
  const heading = [...document.body.querySelectorAll('h2')].find(
    (h) => h.textContent.trim() === 'Approved Systems',
  )
  const panel = heading?.parentElement?.querySelector(':scope > .settings-panel')
  if (!panel) throw new Error('No approved-systems table')
  return panel
}

/** The inline add/edit form, or null while it is closed. */
const editor = () => document.body.querySelector('.deck-system-editor')
const editorTitle = () => editor()?.querySelector('h3')?.textContent.trim()

describe('admin DeckSystemsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the lists', () => {
    it('lists the approved systems by name with their card counts', async () => {
      stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      expect(tableRows(approvedTable())).toEqual([
        ['3', 'Lenormand', 'Len', '36', ''],
        ['1', 'Rider-Waite-Smith', 'RWS', '78', ''],
        ['2', 'Thoth', 'Thoth', '78', ''],
      ])
      expect(document.body.textContent).not.toContain('Pending Submissions')
    })

    it('lists pending submissions apart, with a count and who submitted them', async () => {
      stubApi(loads(systems, pending))
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      const heading = [...document.body.querySelectorAll('h2')].find((h) =>
        h.textContent.includes('Pending Submissions'),
      )
      expect(heading?.textContent.replace(/\s+/g, ' ').trim()).toBe('Pending Submissions 1')
      expect([...row('Sola Busca').cells].map((td) => td.textContent.trim())).toEqual([
        '9',
        'Sola Busca',
        'SB',
        '78',
        '7',
        '',
      ])
      expect(column(1, approvedTable())).not.toContain('Sola Busca')
    })

    it('filters by name or short name, and says when nothing matches', async () => {
      stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      const search = labelled('Search by name or short name...')

      await typeInto(search, 'len')
      expect(column(1, approvedTable())).toEqual(['Lenormand'])

      await typeInto(search, 'rws')
      expect(column(1, approvedTable())).toEqual(['Rider-Waite-Smith'])

      await typeInto(search, 'zzz')
      expect(tableRows(approvedTable())).toEqual([])
      expect(document.body.textContent).toContain('No deck systems match your search.')
    })

    it('sorts by a column, and reverses on a second click', async () => {
      stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      await click(header('Total Cards', approvedTable()))
      expect(column(1, approvedTable())[0]).toBe('Lenormand')

      await click(header('ID', approvedTable()))
      expect(column(0, approvedTable())).toEqual(['1', '2', '3'])

      await click(header('ID', approvedTable()))
      expect(column(0, approvedTable())).toEqual(['3', '2', '1'])
    })

    it('drops to the login page when the session has expired', async () => {
      const expired = { status: 401, body: { error: 'Unauthorized' } }
      stubApi({
        'GET /admin/deck-systems': expired,
        'GET /admin/deck-systems/pending': expired,
      })
      const { router } = await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      // The login route's component loads lazily, so the navigation lands a beat later.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
      expect(useUser().currentUser.value).toBeNull()
      expect(toasts()).toEqual([])
    })
  })

  describe('adding a system', () => {
    it('opens a blank 78-card form, and holds Create back until every card is named', async () => {
      stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      await click(button('Add Deck System'))

      expect(editorTitle()).toBe('Add Deck System')
      expect(button('Add Deck System').disabled).toBe(true)
      expect(valueOf(labelled('Name'))).toBe('')
      expect(valueOf(labelled('Total Cards'))).toBe('78')
      expect(cardHeadings()).toHaveLength(78)
      expect(button('Create').disabled).toBe(true)
      expect(document.body.textContent).toContain('All cards must have a title before saving.')
    })

    it('resizes the card list with the total, keeping the names typed', async () => {
      stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Add Deck System'))

      await nameCards(['Rider', 'Clover', 'Ship'])
      await enterNumber(labelled('Total Cards'), 2)
      expect(cardHeadings()).toEqual(['1 Rider', '2 Clover'])
      expect(button('Create').disabled).toBe(false)

      await enterNumber(labelled('Total Cards'), 3)
      expect(cardHeadings()).toEqual(['1 Rider', '2 Clover', '3 Untitled Card'])
      expect(button('Create').disabled).toBe(true)
    })

    it('creates it through the submission endpoint, then refreshes the lists and closes', async () => {
      const created = system(4, 'Lenormand Grand', 'LG', { total_cards: 2 })
      let saved = false
      const api = stubApi({
        ...loads(),
        'GET /admin/deck-systems': () => ({ body: saved ? [...systems, created] : systems }),
        'POST /deck-systems': () => {
          saved = true
          return { status: 201, body: created }
        },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Add Deck System'))

      await typeInto(labelled('Name'), 'Lenormand Grand')
      await typeInto(labelled('Short Name'), 'LG')
      await nameCards(['Rider', 'Clover'])
      api.mockClear()
      await click(button('Create'))

      expect(apiCalls(api)).toEqual([
        [
          'POST',
          '/api/deck-systems',
          {
            name: 'Lenormand Grand',
            short_name: 'LG',
            total_cards: 2,
            cards: [card(0, 1, 'Rider'), card(0, 2, 'Clover')],
          },
        ],
        ['GET', '/api/admin/deck-systems'],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(toasts()).toEqual(['Deck system created.'])
      expect(editor()).toBeNull()
      expect(column(1, approvedTable())).toContain('Lenormand Grand')
    })

    it("keeps the form open and shows the server's reason when creating fails", async () => {
      const api = stubApi({
        ...loads(),
        'POST /deck-systems': { status: 400, body: { error: 'Short name already in use.' } },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Add Deck System'))
      await typeInto(labelled('Name'), 'Lenormand Grand')
      await typeInto(labelled('Short Name'), 'Len')
      await nameCards(['Rider'])
      api.mockClear()

      await click(button('Create'))

      expect(apiCalls(api)).toHaveLength(1)
      expect(toasts()).toEqual(['Short name already in use.'])
      expect(editorTitle()).toBe('Add Deck System')
      expect(button('Create').disabled).toBe(false)
    })

    it('closes without saving on Cancel or the close button', async () => {
      const api = stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      api.mockClear()

      await click(button('Add Deck System'))
      await click(button('Cancel'))
      expect(editor()).toBeNull()

      await click(button('Add Deck System'))
      await click(button('close'))
      expect(editor()).toBeNull()
      expect(api).not.toHaveBeenCalled()
    })
  })

  describe('editing a system', () => {
    it('loads the full system with its cards into the form', async () => {
      const api = stubApi({ ...loads(), 'GET /admin/deck-systems/3': { body: lenormand } })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      api.mockClear()

      await click(button('Edit', row('Lenormand')))

      expect(apiCalls(api)).toEqual([['GET', '/api/admin/deck-systems/3']])
      expect(editorTitle()).toBe('Edit Deck System #3')
      expect(valueOf(labelled('Name'))).toBe('Lenormand')
      expect(valueOf(labelled('Short Name'))).toBe('Len')
      expect(valueOf(labelled('Total Cards'))).toBe('2')
      expect(cardHeadings()).toEqual(['1 Rider', '2 Clover'])
      expect(button('Save').disabled).toBe(false)
    })

    it('saves the whole system over the old one and refreshes the lists', async () => {
      const api = stubApi({
        ...loads(),
        'GET /admin/deck-systems/3': { body: lenormand },
        'PUT /admin/deck-systems/3': (body) => ({ body }),
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Edit', row('Lenormand')))

      await typeInto(labelled('Name'), 'Petit Lenormand')
      api.mockClear()
      await click(button('Save'))

      expect(apiCalls(api)).toEqual([
        ['PUT', '/api/admin/deck-systems/3', { ...lenormand, name: 'Petit Lenormand' }],
        ['GET', '/api/admin/deck-systems'],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(toasts()).toEqual(['Deck system updated.'])
      expect(editor()).toBeNull()
    })

    it('keeps the form open when saving fails', async () => {
      stubApi({
        ...loads(),
        'GET /admin/deck-systems/3': { body: lenormand },
        'PUT /admin/deck-systems/3': { status: 404, body: { error: 'Deck system not found.' } },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Edit', row('Lenormand')))

      await click(button('Save'))

      expect(toasts()).toEqual(['Deck system not found.'])
      expect(editorTitle()).toBe('Edit Deck System #3')
    })

    it("opens no form when the system can't be loaded", async () => {
      stubApi({
        ...loads(),
        'GET /admin/deck-systems/3': { status: 404, body: { error: 'Deck system not found.' } },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      await click(button('Edit', row('Lenormand')))

      expect(toasts()).toEqual(['Deck system not found.'])
      expect(editor()).toBeNull()
    })
  })

  describe('row actions', () => {
    it('approves a pending submission and refreshes both lists', async () => {
      const approved = { ...pending[0], approved: true }
      let done = false
      const api = stubApi({
        'GET /admin/deck-systems': () => ({ body: done ? [...systems, approved] : systems }),
        'GET /admin/deck-systems/pending': () => ({ body: done ? [] : pending }),
        'POST /admin/deck-systems/9/approve': () => {
          done = true
          return { body: approved }
        },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      api.mockClear()

      await click(button('Approve', row('Sola Busca')))

      expect(apiCalls(api)[0]).toEqual(['POST', '/api/admin/deck-systems/9/approve', {}])
      expect(toasts()).toEqual(['Deck system approved.'])
      expect(document.body.textContent).not.toContain('Pending Submissions')
      expect(column(1, approvedTable())).toContain('Sola Busca')
    })

    it('edits a pending submission from its own row', async () => {
      stubApi({
        ...loads(systems, pending),
        'GET /admin/deck-systems/9': { body: { ...pending[0], cards: [] } },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })

      await click(button('Edit', row('Sola Busca')))

      expect(editorTitle()).toBe('Edit Deck System #9')
    })

    it('asks before deleting, and deletes nothing on Cancel', async () => {
      const api = stubApi(loads())
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      api.mockClear()

      await click(button('Delete', row('Thoth')))
      expect(confirmDialog()?.textContent).toContain(
        'Delete "Thoth"? This will also delete all card data for this system. Decks using this system will need to be reassigned.',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
    })

    it('deletes on confirmation and refreshes the lists', async () => {
      let deleted = false
      const api = stubApi({
        ...loads(),
        'GET /admin/deck-systems': () => ({
          body: deleted ? systems.filter((s) => s.deck_system_id !== 2) : systems,
        }),
        'DELETE /admin/deck-systems/2': () => {
          deleted = true
          return { status: 204 }
        },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Delete', row('Thoth')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/deck-systems/2'],
        ['GET', '/api/admin/deck-systems'],
        ['GET', '/api/admin/deck-systems/pending'],
      ])
      expect(toasts()).toEqual(['Deck system deleted.'])
      expect(column(1, approvedTable())).toEqual(['Lenormand', 'Rider-Waite-Smith'])
    })

    it('deletes a pending submission from its own row', async () => {
      const api = stubApi({
        ...loads(systems, pending),
        'DELETE /admin/deck-systems/9': { status: 204 },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Delete', row('Sola Busca')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)[0]).toEqual(['DELETE', '/api/admin/deck-systems/9'])
    })

    it('keeps the system listed when the delete fails', async () => {
      stubApi({
        ...loads(),
        'DELETE /admin/deck-systems/2': { status: 404, body: { error: 'Deck system not found.' } },
      })
      await mountPage(DeckSystemsView, { path: '/admin/deck-systems' })
      await click(button('Delete', row('Thoth')))

      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Deck system not found.'])
      expect(column(1, approvedTable())).toContain('Thoth')
      expect(hasButton('Delete', row('Thoth'))).toBe(true)
    })
  })
})
