import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import SpreadsView from '@/views/admin/SpreadsView.vue'
import { useUser } from '@/composables/useUser'
import { NETWORK_ERROR_MESSAGE } from '@/composables/apiClient'
import { formatDateTime } from '@/utils/datetime'
import { cleanup, stubApi, signIn, testUser } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  hasButton,
  click,
  confirmDialog,
  answerConfirm,
  dialog,
  toasts,
  row,
  tableRows,
  column,
  header,
  labelled,
  valueOf,
  typeInto,
  apiCalls,
  choose,
} from './helpers/page'
import type { PendingSpread, Spread, SpreadCategory, SpreadPosition } from '@/types'

const position = (order: number, title: string, x = 20 * order, y = 50): SpreadPosition => ({
  order,
  title,
  x,
  y,
  rotation: 0,
})

const category = (category_id: number, name: string, sort_order: number, spread_count = 0) => ({
  category_id,
  name,
  sort_order,
  spread_count,
})
const categories: SpreadCategory[] = [
  category(1, 'General', 1, 2),
  category(2, 'Love & relationships', 2, 1),
  category(3, 'Career', 3),
]

const spread = (
  spread_id: number,
  name: string,
  positions: SpreadPosition[],
  category_id = 1,
): Spread => {
  // Its category's name and place, as the API joins them in.
  const joined = categories.find((c) => c.category_id === category_id)
  return {
    spread_id,
    name,
    description: `About ${name}`,
    card_count: positions.length,
    category_id,
    category_name: joined?.name ?? '',
    category_sort_order: joined?.sort_order ?? 0,
    positions,
  }
}

const pendingSpread = (
  pending_id: number,
  name: string,
  overrides: Partial<PendingSpread> = {},
): PendingSpread => ({
  pending_id,
  name,
  description: '',
  card_count: 2,
  positions: [position(1, 'Heart'), position(2, 'Mind')],
  submitter: 'Morgana',
  user_id: 7,
  submitted_at: '2026-10-27 08:30:00',
  ...overrides,
})

const threeCard = spread(
  1,
  'Three Card',
  [position(1, 'Past'), position(2, 'Present'), position(3, 'Future')],
  2,
)
const celtic = spread(2, 'Celtic Cross', [position(1, 'Situation'), position(2, 'Challenge')])
const single = spread(3, 'Single Card', [position(1, 'Answer')])
const spreads = [celtic, single, threeCard]

// Out of order on purpose: the preview lists positions by their `order`.
const crossroads = pendingSpread(5, 'Crossroads', {
  description: 'Two paths, one choice.',
  card_count: 3,
  positions: [position(3, 'Choice'), position(1, ''), position(2, 'Path B')],
})
const anonymous = pendingSpread(6, 'Quick Look', { submitter: '', user_id: null })

const loads = (approved: Spread[] = spreads, pending: PendingSpread[] = []) => ({
  'GET /admin/spreads': { body: approved },
  'GET /admin/spread-categories': { body: categories },
  'GET /admin/pending-spreads': { body: pending },
})

const tables = () => document.body.querySelectorAll('table')
const spreadsTable = (): Element => tables()[0]
const queueTable = (): Element | undefined => tables()[1]
const pageTitle = () => document.body.querySelector('h1')?.textContent.trim()

function descriptionField(): HTMLElement {
  const field = document.body.querySelector<HTMLElement>(
    'textarea[placeholder="Describe the spread. Markdown is supported."]',
  )
  if (!field) throw new Error('No description field')
  return field
}

/** The open preview of the Crossroads submission. */
function preview(): HTMLElement {
  const open = dialog('Crossroads')
  if (!open) throw new Error('The preview is not open')
  return open
}

/** Place the first card still waiting in the editor's "Unplaced Cards" tray. */
async function placeNextCard(): Promise<void> {
  const token = document.body.querySelector<HTMLElement>('button[title^="Place card"]')
  if (!token) throw new Error('No unplaced card to place')
  await click(token)
}

describe('admin SpreadsView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(cleanup)

  describe('the spread list', () => {
    it('lists the spreads by ID with their categories and card counts', async () => {
      stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      expect(tableRows(spreadsTable())).toEqual([
        ['1', 'Three Card', 'Love & relationships', '3', ''],
        ['2', 'Celtic Cross', 'General', '2', ''],
        ['3', 'Single Card', 'General', '1', ''],
      ])
      expect(document.body.textContent).not.toContain('No spreads yet.')
    })

    it('says how to add one when there are no spreads yet', async () => {
      stubApi(loads([]))
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      expect(tableRows(spreadsTable())).toEqual([])
      expect(document.body.textContent).toContain(
        'No spreads yet. Click "Add Spread" to create one.',
      )
    })

    it('filters by name, category or ID as the admin types', async () => {
      stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      const search = labelled('Search spreads by name, category or ID...')

      await typeInto(search, 'celtic')
      expect(column(1, spreadsTable())).toEqual(['Celtic Cross'])

      await typeInto(search, '3')
      expect(column(1, spreadsTable())).toEqual(['Single Card'])

      await typeInto(search, 'love')
      expect(column(1, spreadsTable())).toEqual(['Three Card'])
    })

    it('sorts by a column, and reverses on a second click', async () => {
      stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      await click(header('Name', spreadsTable()))
      expect(column(1, spreadsTable())).toEqual(['Celtic Cross', 'Single Card', 'Three Card'])

      await click(header('Cards', spreadsTable()))
      await click(header('Cards', spreadsTable()))
      expect(column(1, spreadsTable())).toEqual(['Three Card', 'Celtic Cross', 'Single Card'])

      // By the categories' own order: General before Love & relationships.
      await click(header('Category', spreadsTable()))
      expect(column(2, spreadsTable())).toEqual(['General', 'General', 'Love & relationships'])
    })

    it('drops to the login page when the session has expired', async () => {
      const expired = { status: 401, body: { error: 'Unauthorized' } }
      stubApi({
        'GET /admin/spreads': expired,
        'GET /admin/spread-categories': expired,
        'GET /admin/pending-spreads': expired,
      })
      const { router } = await mountPage(SpreadsView, { path: '/admin/spreads' })

      // The login route's component loads lazily, so the navigation lands a beat later.
      await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
      expect(useUser().currentUser.value).toBeNull()
      expect(toasts()).toEqual([])
    })

    it("says why the lists couldn't load when the network is down", async () => {
      vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      expect(toasts()).toContain(NETWORK_ERROR_MESSAGE)
      expect(tableRows(spreadsTable())).toEqual([])
    })
  })

  describe('the submission queue', () => {
    it('says so when nothing is waiting for review', async () => {
      stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      expect(document.body.textContent).toContain('No pending submissions right now.')
      expect(queueTable()).toBeUndefined()
    })

    it('lists each submission with when, who and how many cards, under a count', async () => {
      stubApi(loads(spreads, [crossroads, anonymous]))
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      const heading = [...document.body.querySelectorAll('h2')].find((h) =>
        h.textContent.includes('Submission Queue'),
      )
      expect(heading?.textContent.replace(/\s+/g, ' ').trim()).toBe('Submission Queue 2')
      expect(tableRows(queueTable())).toEqual([
        [formatDateTime('2026-10-27 08:30:00'), 'Crossroads', 'Morgana', '3', ''],
        [formatDateTime('2026-10-27 08:30:00'), 'Quick Look', '—', '2', ''],
      ])
    })

    it('previews a submission: who sent it, its positions in order, and its description', async () => {
      stubApi(loads(spreads, [crossroads]))
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      await click(button('Preview', row('Crossroads')))

      expect(preview().textContent).toContain('Submitted by Morgana')
      expect([...preview().querySelectorAll('ol li')].map((li) => li.textContent.trim())).toEqual([
        '(untitled)',
        'Path B',
        'Choice',
      ])
      expect(preview().textContent).toContain('Two paths, one choice.')
    })

    it('closes the preview with Close', async () => {
      stubApi(loads(spreads, [crossroads]))
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Preview', row('Crossroads')))

      // The footer's Close, not the corner ×, which shares its name.
      const footer = preview().querySelector('footer')
      if (!footer) throw new Error('The preview has no footer')
      await click(button('Close', footer))

      expect(dialog('Crossroads')).toBeNull()
    })

    it('approves a submission into the spread list and empties the queue', async () => {
      const published = spread(4, 'Crossroads', crossroads.positions)
      let approved = false
      const api = stubApi({
        'GET /admin/spreads': () => ({ body: approved ? [...spreads, published] : spreads }),
        'GET /admin/spread-categories': { body: categories },
        'GET /admin/pending-spreads': () => ({ body: approved ? [] : [crossroads] }),
        'POST /admin/pending-spreads/5/approve': () => {
          approved = true
          return { status: 201, body: published }
        },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      api.mockClear()

      // From the row, into the first category (the server's default too).
      await click(button('Approve into General', row('Crossroads')))

      expect(apiCalls(api)).toEqual([
        ['POST', '/api/admin/pending-spreads/5/approve', {}],
        ['GET', '/api/admin/spreads'],
        ['GET', '/api/admin/pending-spreads'],
        ['GET', '/api/admin/spread-categories'],
      ])
      expect(toasts()).toEqual(['Spread approved and published.'])
      expect(column(1, spreadsTable())).toContain('Crossroads')
      expect(document.body.textContent).toContain('No pending submissions right now.')
    })

    it('approves from the preview into the category chosen there, and closes it', async () => {
      let approved = false
      const api = stubApi({
        'GET /admin/spreads': { body: spreads },
        'GET /admin/spread-categories': { body: categories },
        'GET /admin/pending-spreads': () => ({ body: approved ? [] : [crossroads] }),
        'POST /admin/pending-spreads/5/approve': () => {
          approved = true
          return { status: 201, body: spread(4, 'Crossroads', crossroads.positions, 3) }
        },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Preview', row('Crossroads')))

      expect(labelled('Publish in').textContent.trim()).toBe('General')
      await choose(labelled('Publish in'), 'Career')
      await click(button('Approve', preview()))

      expect(apiCalls(api)).toContainEqual([
        'POST',
        '/api/admin/pending-spreads/5/approve',
        { category_id: 3 },
      ])
      expect(toasts()).toEqual(['Spread approved and published.'])
      expect(dialog('Crossroads')).toBeNull()
    })

    it('shows what each submitted position asks, under its title', async () => {
      stubApi(
        loads(spreads, [
          pendingSpread(7, 'Crossroads', {
            positions: [
              { ...position(1, 'Left'), description: 'The road you know' },
              position(2, 'Right'),
            ],
          }),
        ]),
      )
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      await click(button('Preview', row('Crossroads')))

      expect(
        [...preview().querySelectorAll('.pending-position-about')].map((el) => el.textContent),
      ).toEqual(['The road you know'])
    })

    it("keeps the submission queued, preview open, and shows the server's reason when approving fails", async () => {
      stubApi({
        ...loads(spreads, [crossroads]),
        'POST /admin/pending-spreads/5/approve': {
          status: 500,
          body: { error: 'Failed to approve spread' },
        },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Preview', row('Crossroads')))

      await click(button('Approve', preview()))

      expect(toasts()).toEqual(['Failed to approve spread'])
      expect(dialog('Crossroads')).not.toBeNull()
      expect(column(1, queueTable())).toEqual(['Crossroads'])
    })

    it('asks before rejecting, and keeps the submission on Cancel', async () => {
      const api = stubApi(loads(spreads, [crossroads]))
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      api.mockClear()

      await click(button('Reject', row('Crossroads')))
      expect(confirmDialog()?.textContent).toContain(
        'Reject and permanently delete the submission "Crossroads"?',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
      expect(column(1, queueTable())).toEqual(['Crossroads'])
    })

    it('rejects on confirmation and reloads only the queue', async () => {
      let rejected = false
      const api = stubApi({
        'GET /admin/spreads': { body: spreads },
        'GET /admin/spread-categories': { body: categories },
        'GET /admin/pending-spreads': () => ({ body: rejected ? [] : [crossroads] }),
        'DELETE /admin/pending-spreads/5': () => {
          rejected = true
          return { status: 204 }
        },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Reject', row('Crossroads')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/pending-spreads/5'],
        ['GET', '/api/admin/pending-spreads'],
      ])
      expect(toasts()).toEqual(['Submission rejected.'])
      expect(document.body.textContent).toContain('No pending submissions right now.')
    })

    it('rejects from the preview and closes it', async () => {
      let rejected = false
      stubApi({
        'GET /admin/spreads': { body: spreads },
        'GET /admin/spread-categories': { body: categories },
        'GET /admin/pending-spreads': () => ({ body: rejected ? [] : [crossroads] }),
        'DELETE /admin/pending-spreads/5': () => {
          rejected = true
          return { status: 204 }
        },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Preview', row('Crossroads')))

      await click(button('Reject', preview()))
      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Submission rejected.'])
      expect(dialog('Crossroads')).toBeNull()
    })

    it("keeps the submission queued and shows the server's reason when rejecting fails", async () => {
      stubApi({
        ...loads(spreads, [crossroads]),
        'DELETE /admin/pending-spreads/5': { status: 500, body: { error: 'Failed to reject' } },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Reject', row('Crossroads')))

      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Failed to reject'])
      expect(column(1, queueTable())).toEqual(['Crossroads'])
    })
  })

  describe('adding and editing a spread', () => {
    it('creates a spread once its card is placed, then returns to the refreshed list', async () => {
      const created = spread(4, 'Daily Draw', [position(1, '', 40, 40)])
      let saved = false
      const api = stubApi({
        'GET /admin/spreads': () => ({ body: saved ? [...spreads, created] : spreads }),
        'GET /admin/spread-categories': { body: categories },
        'GET /admin/pending-spreads': { body: [] },
        'POST /admin/spreads': () => {
          saved = true
          return { status: 201, body: created }
        },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      await click(button('Add Spread'))
      expect(pageTitle()).toBe('Add Spread')
      expect(valueOf(labelled('Spread Name'))).toBe('')

      await typeInto(labelled('Spread Name'), '  Daily Draw  ')
      await typeInto(descriptionField(), 'One card for the day.')
      await placeNextCard()
      api.mockClear()
      await click(button('Save Spread'))

      expect(apiCalls(api)).toEqual([
        [
          'POST',
          '/api/admin/spreads',
          {
            name: 'Daily Draw',
            description: 'One card for the day.',
            card_count: 1,
            positions: [{ order: 1, title: '', x: 40, y: 40, rotation: 0 }],
            // A new spread starts in the first category.
            category_id: 1,
          },
        ],
        ['GET', '/api/admin/spreads'],
        ['GET', '/api/admin/spread-categories'],
      ])
      expect(toasts()).toEqual(['Spread created.'])
      expect(pageTitle()).toBe('Manage Spreads')
      expect(column(1, spreadsTable())).toContain('Daily Draw')
    })

    it("opens a spread's name and layout and saves the changes over it", async () => {
      const api = stubApi({
        ...loads(),
        'PUT /admin/spreads/1': { body: { ...threeCard, name: 'Past, Present, Future' } },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })

      await click(button('Edit', row('Three Card')))
      expect(pageTitle()).toBe('Edit Spread')
      expect(valueOf(labelled('Spread Name'))).toBe('Three Card')
      expect(valueOf(labelled('Number of Cards'))).toBe('3')

      await typeInto(labelled('Spread Name'), 'Past, Present, Future')
      expect(labelled('Category').textContent.trim()).toBe('Love & relationships')
      await choose(labelled('Category'), 'Career')
      api.mockClear()
      await click(button('Save Spread'))

      expect(apiCalls(api)[0]).toEqual([
        'PUT',
        '/api/admin/spreads/1',
        {
          name: 'Past, Present, Future',
          description: 'About Three Card',
          card_count: 3,
          positions: threeCard.positions,
          category_id: 3,
        },
      ])
      expect(toasts()).toEqual(['Spread updated.'])
      expect(pageTitle()).toBe('Manage Spreads')
    })

    it('stays in the editor and says the save failed when the server refuses it', async () => {
      stubApi({
        ...loads(),
        'PUT /admin/spreads/1': { status: 404, body: { error: 'Spread not found' } },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Edit', row('Three Card')))

      await click(button('Save Spread'))

      expect(toasts()).toEqual(['Spread not found'])
      expect(pageTitle()).toBe('Edit Spread')
      expect(document.body.textContent).toContain('Failed to save the spread. Please try again.')
    })

    it('goes back to the list on Cancel without saving', async () => {
      const api = stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Edit', row('Three Card')))
      api.mockClear()

      await click(button('Cancel'))

      expect(api).not.toHaveBeenCalled()
      expect(pageTitle()).toBe('Manage Spreads')
      expect(hasButton('Save Spread')).toBe(false)
    })
  })

  describe('deleting a spread', () => {
    it('asks first, and deletes nothing on Cancel', async () => {
      const api = stubApi(loads())
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      api.mockClear()

      await click(button('Delete', row('Celtic Cross')))
      expect(confirmDialog()?.textContent).toContain(
        'Delete "Celtic Cross"? Existing readings that used it keep their own saved copy and are unaffected.',
      )

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
    })

    it('deletes on confirmation and reloads the list', async () => {
      let deleted = false
      const api = stubApi({
        'GET /admin/spreads': () => ({
          body: deleted ? spreads.filter((s) => s.spread_id !== 2) : spreads,
        }),
        'GET /admin/spread-categories': { body: categories },
        'GET /admin/pending-spreads': { body: [] },
        'DELETE /admin/spreads/2': () => {
          deleted = true
          return { status: 204 }
        },
      })
      await mountPage(SpreadsView, { path: '/admin/spreads' })
      await click(button('Delete', row('Celtic Cross')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/spreads/2'],
        ['GET', '/api/admin/spreads'],
        ['GET', '/api/admin/spread-categories'],
      ])
      expect(toasts()).toEqual(['Spread deleted.'])
      expect(column(1, spreadsTable())).toEqual(['Three Card', 'Single Card'])
    })
  })
})
