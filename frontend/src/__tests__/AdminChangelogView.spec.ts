import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import ChangelogView from '@/views/admin/ChangelogView.vue'
import { useUser } from '@/composables/useUser'
import { cleanup, stubApi, signIn, testUser } from './helpers/app'
import {
  mountPage,
  resetPageState,
  button,
  click,
  confirmDialog,
  answerConfirm,
  dialog,
  toasts,
  row,
  tableRows,
  column,
  labelled,
  valueOf,
  typeInto,
  apiCalls,
} from './helpers/page'
import type { ChangelogEntry } from '@/types'

const entries: ChangelogEntry[] = [
  { entry_id: 2, title: 'Passkeys', entry_date: '2026-10-01', body: 'Sign in with a **passkey**.' },
  { entry_id: 1, title: 'Launch', entry_date: '2026-01-01', body: 'TarotGen.io is live.' },
]

const errorNotice = () => document.body.querySelector('[role="dialog"] .notification')

describe('admin ChangelogView', () => {
  beforeEach(() => {
    resetPageState()
    signIn(testUser({ is_admin: true }))
    // A failed call is mirrored to the console by useToasts; keep the run quiet.
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })
  afterEach(() => {
    vi.useRealTimers()
    return cleanup()
  })

  it('lists the entries with their dates, as the server orders them', async () => {
    stubApi({ 'GET /admin/changelog': { body: entries } })
    await mountPage(ChangelogView, { path: '/admin/changelog' })

    expect(tableRows()).toEqual([
      ['2026-10-01', 'Passkeys', ''],
      ['2026-01-01', 'Launch', ''],
    ])
    expect(document.body.textContent).not.toContain('No entries yet.')
  })

  it('invites the admin to add the first entry when there are none', async () => {
    stubApi({ 'GET /admin/changelog': { body: [] } })
    await mountPage(ChangelogView, { path: '/admin/changelog' })

    expect(document.body.textContent).toContain('No entries yet. Click "Add Entry" to create one.')
  })

  describe('adding an entry', () => {
    it('opens a blank entry dated today', async () => {
      // Only Date: flushPromises still needs real timers.
      vi.useFakeTimers({ toFake: ['Date'] })
      vi.setSystemTime(new Date('2026-10-28T23:30:00Z'))
      stubApi({ 'GET /admin/changelog': { body: entries } })
      await mountPage(ChangelogView, { path: '/admin/changelog' })

      await click(button('Add Entry'))

      expect(dialog('Add Entry')).not.toBeNull()
      expect(valueOf(labelled('Title'))).toBe('')
      expect(valueOf(labelled('Date'))).toBe('2026-10-28')
      expect(valueOf(labelled('Body'))).toBe('')
    })

    it('requires a title before sending anything', async () => {
      const api = stubApi({ 'GET /admin/changelog': { body: entries } })
      await mountPage(ChangelogView, { path: '/admin/changelog' })
      await click(button('Add Entry'))
      await typeInto(labelled('Title'), '   ')
      api.mockClear()

      await click(button('Create'))

      expect(errorNotice()?.textContent).toBe('A title is required.')
      expect(api).not.toHaveBeenCalled()
    })

    it('creates the entry, reloads the list and closes', async () => {
      const created = {
        entry_id: 3,
        title: 'Deck images',
        entry_date: '2026-10-28',
        body: 'Faster.',
      }
      let saved = false
      const api = stubApi({
        'GET /admin/changelog': () => ({ body: saved ? [created, ...entries] : entries }),
        'POST /admin/changelog': () => {
          saved = true
          return { status: 201, body: created }
        },
      })
      await mountPage(ChangelogView, { path: '/admin/changelog' })
      await click(button('Add Entry'))

      await typeInto(labelled('Title'), 'Deck images')
      await typeInto(labelled('Date'), '2026-10-28')
      await typeInto(labelled('Body'), 'Faster.')
      api.mockClear()
      await click(button('Create'))

      expect(apiCalls(api)).toEqual([
        [
          'POST',
          '/api/admin/changelog',
          { title: 'Deck images', entry_date: '2026-10-28', body: 'Faster.' },
        ],
        ['GET', '/api/admin/changelog'],
      ])
      expect(toasts()).toEqual(['Entry created.'])
      expect(dialog('Add Entry')).toBeNull()
      expect(column(1)).toEqual(['Deck images', 'Passkeys', 'Launch'])
    })

    it('keeps the form open and says the save failed when the server refuses it', async () => {
      stubApi({
        'GET /admin/changelog': { body: entries },
        'POST /admin/changelog': { status: 500, body: { error: 'Failed to create entry.' } },
      })
      await mountPage(ChangelogView, { path: '/admin/changelog' })
      await click(button('Add Entry'))
      await typeInto(labelled('Title'), 'Deck images')

      await click(button('Create'))

      expect(toasts()).toEqual(['Failed to create entry.'])
      expect(errorNotice()?.textContent).toBe('Failed to save the entry. Please try again.')
      expect(valueOf(labelled('Title'))).toBe('Deck images')
    })

    it('starts the next attempt without the last error', async () => {
      stubApi({ 'GET /admin/changelog': { body: entries } })
      await mountPage(ChangelogView, { path: '/admin/changelog' })
      await click(button('Add Entry'))
      await click(button('Create'))
      expect(errorNotice()).not.toBeNull()

      await click(button('Cancel'))
      await click(button('Add Entry'))

      expect(errorNotice()).toBeNull()
    })
  })

  describe('editing an entry', () => {
    it('opens the entry as it is and saves the changes over it', async () => {
      const api = stubApi({
        'GET /admin/changelog': { body: entries },
        'PUT /admin/changelog/2': { body: { ...entries[0], title: 'Passkeys are here' } },
      })
      await mountPage(ChangelogView, { path: '/admin/changelog' })

      await click(button('Edit', row('Passkeys')))
      expect(dialog('Edit Entry')).not.toBeNull()
      expect(valueOf(labelled('Date'))).toBe('2026-10-01')
      expect(valueOf(labelled('Body'))).toBe('Sign in with a **passkey**.')

      await typeInto(labelled('Title'), 'Passkeys are here')
      api.mockClear()
      await click(button('Save'))

      expect(apiCalls(api)).toEqual([
        [
          'PUT',
          '/api/admin/changelog/2',
          {
            title: 'Passkeys are here',
            entry_date: '2026-10-01',
            body: 'Sign in with a **passkey**.',
          },
        ],
        ['GET', '/api/admin/changelog'],
      ])
      expect(toasts()).toEqual(['Entry updated.'])
      expect(dialog('Edit Entry')).toBeNull()
    })

    it('closes on Cancel without saving', async () => {
      const api = stubApi({ 'GET /admin/changelog': { body: entries } })
      await mountPage(ChangelogView, { path: '/admin/changelog' })
      await click(button('Edit', row('Launch')))
      api.mockClear()

      await click(button('Cancel'))

      expect(dialog('Edit Entry')).toBeNull()
      expect(api).not.toHaveBeenCalled()
    })
  })

  describe('deleting an entry', () => {
    it('asks first, and deletes nothing on Cancel', async () => {
      const api = stubApi({ 'GET /admin/changelog': { body: entries } })
      await mountPage(ChangelogView, { path: '/admin/changelog' })
      api.mockClear()

      await click(button('Delete', row('Launch')))
      expect(confirmDialog()?.textContent).toContain('Delete "Launch"? This cannot be undone.')

      await answerConfirm('cancel')
      expect(api).not.toHaveBeenCalled()
    })

    it('deletes on confirmation and reloads the list', async () => {
      let deleted = false
      const api = stubApi({
        'GET /admin/changelog': () => ({ body: deleted ? [entries[0]] : entries }),
        'DELETE /admin/changelog/1': () => {
          deleted = true
          return { status: 204 }
        },
      })
      await mountPage(ChangelogView, { path: '/admin/changelog' })
      await click(button('Delete', row('Launch')))
      api.mockClear()

      await answerConfirm('confirm')

      expect(apiCalls(api)).toEqual([
        ['DELETE', '/api/admin/changelog/1'],
        ['GET', '/api/admin/changelog'],
      ])
      expect(toasts()).toEqual(['Entry deleted.'])
      expect(column(1)).toEqual(['Passkeys'])
    })

    it('keeps the entry listed when the delete fails', async () => {
      stubApi({
        'GET /admin/changelog': { body: entries },
        'DELETE /admin/changelog/1': { status: 500, body: { error: 'Database is locked.' } },
      })
      await mountPage(ChangelogView, { path: '/admin/changelog' })
      await click(button('Delete', row('Launch')))

      await answerConfirm('confirm')

      expect(toasts()).toEqual(['Database is locked.'])
      expect(column(1)).toEqual(['Passkeys', 'Launch'])
    })
  })

  it('drops to the login page when the session has expired', async () => {
    stubApi({ 'GET /admin/changelog': { status: 401, body: { error: 'Unauthorized' } } })
    const { router } = await mountPage(ChangelogView, { path: '/admin/changelog' })

    // The login route's component loads lazily, so the navigation lands a beat later.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(useUser().currentUser.value).toBeNull()
  })
})
