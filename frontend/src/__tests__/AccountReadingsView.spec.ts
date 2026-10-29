import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { TooltipProvider } from 'reka-ui'
import AccountReadingsView from '@/views/AccountReadingsView.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import { useToasts } from '@/composables/useToasts'
import { useConfirm } from '@/composables/useConfirm'
import { mountWithApp, cleanup, stubApi, signIn, testUser } from './helpers/app'
import type { AccountReading } from '@/types'

// The view inside what App.vue gives it: the TooltipProvider the notes editor's
// toolbar needs and the shared ConfirmDialog its confirm() calls open.
const Page = defineComponent({
  render: () => h(TooltipProvider, null, () => [h(AccountReadingsView), h(ConfirmDialog)]),
})

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const pageText = () => text(document.body)
const toastMessages = () => useToasts().toasts.value.map((t) => t.message)

function button(name: string, root: ParentNode = document.body): HTMLButtonElement {
  const found = [...root.querySelectorAll('button')].filter((b) => text(b) === name)
  if (found.length !== 1) throw new Error(`Expected one "${name}" button, found ${found.length}`)
  return found[0]
}

/** The table row whose first cell (the reading's title) reads `title`. */
function row(title: string): HTMLTableRowElement {
  const found = [...document.body.querySelectorAll('tbody tr')].find(
    (tr): tr is HTMLTableRowElement =>
      tr instanceof HTMLTableRowElement && text(tr.cells[0]) === title,
  )
  if (!found) throw new Error(`No reading titled "${title}"`)
  return found
}

const titles = () =>
  [...document.body.querySelectorAll('tbody tr')].map((tr) => text(tr.querySelector('td') ?? tr))

function labelled(label: string): HTMLElement {
  const el = [...document.body.querySelectorAll('label')].find((l) => text(l) === label)
  const control = el && document.getElementById(el.htmlFor)
  if (!control) throw new Error(`Nothing is labelled "${label}"`)
  return control
}

function field(label: string): HTMLInputElement {
  const input = labelled(label)
  if (!(input instanceof HTMLInputElement)) throw new Error(`"${label}" is not a text field`)
  return input
}

async function type(label: string, value: string) {
  const el = field(label)
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function click(el: HTMLElement) {
  el.click()
  await flushPromises()
}

const editDialog = () => document.body.querySelector<HTMLElement>('[role="dialog"]')
const confirmDialog = () => document.body.querySelector<HTMLElement>('[role="alertdialog"]')

async function answer(choice: string) {
  const open = confirmDialog()
  if (!open) throw new Error('No confirm dialog is open')
  await click(button(choice, open))
}

const reading = (reading_id: string, extra: Partial<AccountReading> = {}): AccountReading => ({
  reading_id,
  reading_info: {
    deck_id: 1,
    draw: [
      { card_id: 1, reversed: false },
      { card_id: 2, reversed: true },
      { card_id: 3, reversed: false },
    ],
  },
  reading_time: '2026-10-01 12:00:00',
  user_id: 7,
  hide_user: false,
  reading_name: null,
  reading_notes: null,
  is_final: false,
  password_protected: false,
  ...extra,
})

const named = (reading_id: string, reading_name: string, extra: Partial<AccountReading> = {}) =>
  reading(reading_id, { reading_name, ...extra })

/**
 * Serve the user's readings a page at a time with the total in X-Total-Count, as
 * the API does, plus `handlers`. `rows` is read on every request, so a test can
 * change it; `pages` records each page asked for.
 */
function serve(rows: () => AccountReading[], handlers: Parameters<typeof stubApi>[0] = {}) {
  const pages: { limit: number; offset: number }[] = []
  const api = stubApi({
    'GET /account/readings': (_body, url) => {
      const limit = Number(url.searchParams.get('limit'))
      const offset = Number(url.searchParams.get('offset'))
      pages.push({ limit, offset })
      return {
        body: rows().slice(offset, offset + limit),
        headers: { 'X-Total-Count': String(rows().length) },
      }
    },
    ...handlers,
  })
  // What the view changed: every call but the list reads.
  const writes = () => api.mock.calls.filter(([, init]) => init?.method !== undefined)
  return { pages, writes }
}

const many = (n: number) =>
  Array.from({ length: n }, (_, i) => named(`r${i + 1}`, `Reading ${i + 1}`))

describe('AccountReadingsView', () => {
  beforeEach(() => {
    signIn(testUser())
    useToasts().toasts.value = []
    useConfirm().settle(false)
  })
  afterEach(cleanup)

  describe('the list', () => {
    it('shows it is loading until the first page arrives', async () => {
      vi.stubGlobal(
        'fetch',
        vi.fn(() => new Promise<Response>(() => {})),
      )
      await mountWithApp(Page, { path: '/account/readings' })

      expect(pageText()).toContain('Loading your readings…')
    })

    it('names each reading by its title, else its spread, else its card count', async () => {
      const spread = { spread_id: 3, name: 'Celtic Cross', description: '', positions: [] }
      serve(() => [
        named('a', 'Morning reflection'),
        reading('b', { reading_info: { deck_id: 1, spread, draw: [] } }),
        reading('c'),
        reading('d', { reading_info: { deck_id: 1, draw: [{ card_id: 5, reversed: false }] } }),
      ])
      await mountWithApp(Page, { path: '/account/readings' })

      expect(titles()).toEqual(['Morning reflection', 'Celtic Cross', '3 cards', '1 card'])
    })

    it('shows who can see each reading', async () => {
      serve(() => [
        named('a', 'Locked', { password_protected: true }),
        named('b', 'Anonymous', { hide_user: true }),
        named('c', 'Open'),
      ])
      await mountWithApp(Page, { path: '/account/readings' })

      expect(text(row('Locked'))).toContain('Password')
      expect(text(row('Anonymous'))).toContain('Name hidden')
      expect(text(row('Open'))).toContain('Public')
      expect(text(row('Locked'))).not.toContain('Public')
    })

    it('links each reading to its page', async () => {
      serve(() => [named('abc123', 'Morning reflection')])
      await mountWithApp(Page, { path: '/account/readings' })

      const view = [...row('Morning reflection').querySelectorAll('a')].find(
        (a) => text(a) === 'View',
      )
      expect(view?.getAttribute('href')).toBe('/reading/abc123')
    })

    it('says so when there are no readings yet', async () => {
      serve(() => [])
      await mountWithApp(Page, { path: '/account/readings' })

      expect(pageText()).toContain("You haven't saved any readings yet.")
    })

    it("says the readings couldn't load (rather than that there are none), and retries", async () => {
      vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
      await mountWithApp(Page, { path: '/account/readings' })

      expect(pageText()).toContain("Couldn't load your readings. Network error.")
      expect(pageText()).not.toContain("You haven't saved any readings yet.")

      serve(() => [named('a', 'Morning reflection')])
      await click(button('Try again'))

      expect(titles()).toEqual(['Morning reflection'])
    })

    it('asks for one page at a time and pages through the rest', async () => {
      const { pages } = serve(() => many(30))
      await mountWithApp(Page, { path: '/account/readings' })

      expect(pages).toEqual([{ limit: 25, offset: 0 }])
      expect(titles()).toHaveLength(25)

      await click(button('2'))
      await vi.waitFor(() =>
        expect(titles()).toEqual(
          many(30)
            .slice(25)
            .map((r) => r.reading_name),
        ),
      )
      expect(pages.at(-1)).toEqual({ limit: 25, offset: 25 })
    })

    it('has no pager when everything fits on one page', async () => {
      serve(() => many(25))
      await mountWithApp(Page, { path: '/account/readings' })

      expect([...document.body.querySelectorAll('button')].some((b) => text(b) === '2')).toBe(false)
    })
  })

  describe('editing a reading', () => {
    it('opens with the reading as it is', async () => {
      serve(() => [
        named('a', 'Morning reflection', { hide_user: true, reading_notes: 'The **Tower**.' }),
      ])
      await mountWithApp(Page, { path: '/account/readings' })

      await click(button('Edit', row('Morning reflection')))

      expect(editDialog()).not.toBeNull()
      expect(field('Reading Title').value).toBe('Morning reflection')
      expect(labelled('Hide my display name on this reading').getAttribute('aria-checked')).toBe(
        'true',
      )
      expect(editDialog()?.querySelector('[role="textbox"] strong')?.textContent).toBe('Tower')
      // No password yet, so there's nothing to remove.
      expect(field('Set Password (optional)')).toBeTruthy()
      expect(pageText()).not.toContain('Remove the current password')
    })

    it('saves the title, notes, visibility and a new password, then refreshes the list', async () => {
      let rows = [named('a', 'Morning reflection')]
      const { writes } = serve(() => rows, {
        'PATCH /account/readings/a': () => {
          rows = [named('a', 'Evening reflection', { password_protected: true })]
          return { body: rows[0] }
        },
      })
      const { wrapper } = await mountWithApp(Page, { path: '/account/readings' })

      await click(button('Edit', row('Morning reflection')))
      await type('Reading Title', '  Evening reflection ')
      wrapper.findComponent(MarkdownEditor).vm.$emit('update:modelValue', '## Past')
      await click(labelled('Hide my display name on this reading'))
      await type('Set Password (optional)', 'open sesame')
      await click(button('Save'))

      expect(JSON.parse(String(writes()[0]?.[1]?.body))).toEqual({
        reading_name: 'Evening reflection',
        reading_notes: '## Past',
        hide_user: true,
        password: 'open sesame',
      })
      expect(toastMessages()).toEqual(['Reading updated.'])
      expect(editDialog()).toBeNull()
      expect(text(row('Evening reflection'))).toContain('Password')
    })

    it('removes the password when asked, ignoring the password field', async () => {
      const { writes } = serve(() => [named('a', 'Locked', { password_protected: true })], {
        'PATCH /account/readings/a': { body: named('a', 'Locked') },
      })
      await mountWithApp(Page, { path: '/account/readings' })

      await click(button('Edit', row('Locked')))
      await type('Change Password (optional)', 'half-typed')
      await click(labelled('Remove the current password'))
      expect(document.getElementById('er-password')).toBeNull()
      await click(button('Save'))

      const body = JSON.parse(String(writes()[0]?.[1]?.body))
      expect(body).toMatchObject({ remove_password: true })
      expect(body).not.toHaveProperty('password')
    })

    it('leaves the password alone when the field is left blank', async () => {
      const { writes } = serve(() => [named('a', 'Locked', { password_protected: true })], {
        'PATCH /account/readings/a': { body: named('a', 'Locked', { password_protected: true }) },
      })
      await mountWithApp(Page, { path: '/account/readings' })

      await click(button('Edit', row('Locked')))
      await click(button('Save'))

      const body = JSON.parse(String(writes()[0]?.[1]?.body))
      expect(body).not.toHaveProperty('password')
      expect(body).not.toHaveProperty('remove_password')
    })

    it("keeps the dialog open with the server's reason when a save is refused", async () => {
      serve(() => [named('a', 'Morning reflection')], {
        'PATCH /account/readings/a': {
          status: 422,
          body: { error: 'Password must be at least 4 characters.' },
        },
      })
      await mountWithApp(Page, { path: '/account/readings' })

      await click(button('Edit', row('Morning reflection')))
      await type('Set Password (optional)', 'abc')
      await click(button('Save'))

      expect(editDialog()?.textContent).toContain('Password must be at least 4 characters.')
      expect(toastMessages()).toEqual([])
    })

    it('closes without saving on Cancel', async () => {
      const { writes } = serve(() => [named('a', 'Morning reflection')])
      await mountWithApp(Page, { path: '/account/readings' })

      await click(button('Edit', row('Morning reflection')))
      await click(button('Cancel'))

      expect(editDialog()).toBeNull()
      expect(writes()).toEqual([])
    })
  })

  describe('deleting a reading', () => {
    it('deletes after a warning naming the reading, then refreshes the list', async () => {
      let rows = [named('a', 'Morning reflection'), named('b', 'Evening reflection')]
      const { writes } = serve(() => rows, {
        'DELETE /account/readings/a': () => {
          rows = rows.filter((r) => r.reading_id !== 'a')
          return { status: 204 }
        },
      })
      await mountWithApp(Page, { path: '/account/readings' })

      await click(button('Delete', row('Morning reflection')))
      expect(confirmDialog()?.textContent).toContain('Permanently delete "Morning reflection"?')
      await answer('Delete')

      expect(writes()).toHaveLength(1)
      expect(toastMessages()).toEqual(['Reading deleted.'])
      expect(titles()).toEqual(['Evening reflection'])
    })

    it('keeps the reading when the warning is cancelled', async () => {
      const { writes } = serve(() => [named('a', 'Morning reflection')])
      await mountWithApp(Page, { path: '/account/readings' })

      await click(button('Delete', row('Morning reflection')))
      await answer('Cancel')

      expect(writes()).toEqual([])
      expect(titles()).toEqual(['Morning reflection'])
    })

    it("says why a reading couldn't be deleted", async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {})
      serve(() => [named('a', 'Morning reflection')], {
        'DELETE /account/readings/a': { status: 404, body: { error: 'Reading not found.' } },
      })
      await mountWithApp(Page, { path: '/account/readings' })

      await click(button('Delete', row('Morning reflection')))
      await answer('Delete')

      expect(toastMessages()).toEqual(['Reading not found.'])
      expect(titles()).toEqual(['Morning reflection'])
    })

    it('steps back a page after deleting the only reading on the last one', async () => {
      let rows = many(26)
      const { pages } = serve(() => rows, {
        'DELETE /account/readings/r26': () => {
          rows = rows.slice(0, 25)
          return { status: 204 }
        },
      })
      await mountWithApp(Page, { path: '/account/readings' })
      await click(button('2'))
      await vi.waitFor(() => expect(titles()).toEqual(['Reading 26']))

      await click(button('Delete', row('Reading 26')))
      await answer('Delete')

      await vi.waitFor(() => expect(titles()).toHaveLength(25))
      expect(pages.map((p) => p.offset)).toEqual([0, 25, 25, 0])
    })
  })
})
