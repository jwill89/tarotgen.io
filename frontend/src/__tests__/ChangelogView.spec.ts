import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import ChangelogView from '@/views/ChangelogView.vue'
import { useChangelog } from '@/composables/useChangelog'
import { mountWithApp, cleanup, stubApi } from './helpers/app'
import type { ChangelogEntry } from '@/types'

const entry = (entry_id: number, title: string, entry_date: string, body = ''): ChangelogEntry => ({
  entry_id,
  title,
  body,
  entry_date,
})

const longDate = (y: number, m: number, d: number) =>
  new Date(y, m - 1, d).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })

const articles = () =>
  [...document.body.querySelectorAll('article')].map((a) => ({
    date: a.querySelector('time')?.textContent,
    title: a.querySelector('h2')?.textContent,
  }))

describe('ChangelogView', () => {
  beforeEach(() => {
    const state = useChangelog()
    state.entries.value = []
    state.loaded.value = false
    state.error.value = ''
  })
  afterEach(cleanup)

  it('lists the entries with their dates written out', async () => {
    stubApi({
      'GET /changelog': {
        body: [entry(2, 'Passkeys', '2026-10-01'), entry(1, 'Launch', '2026-01-15')],
      },
    })
    await mountWithApp(ChangelogView, { path: '/changelog' })

    expect(articles()).toEqual([
      { date: longDate(2026, 10, 1), title: 'Passkeys' },
      { date: longDate(2026, 1, 15), title: 'Launch' },
    ])
    expect(document.body.textContent).not.toContain('No changelog entries yet.')
  })

  it('keeps the date it was written for, west of UTC too', async () => {
    // vitest.setup.ts pins TZ=UTC; this one test moves west of it.
    vi.stubEnv('TZ', 'America/Los_Angeles')
    try {
      // Parsed as UTC midnight, '2026-10-01' would show as 30 September here.
      expect(new Date('2026-10-01').getDate()).toBe(30)

      stubApi({ 'GET /changelog': { body: [entry(1, 'Passkeys', '2026-10-01')] } })
      await mountWithApp(ChangelogView, { path: '/changelog' })

      expect(articles()[0]?.date).toBe(longDate(2026, 10, 1))
    } finally {
      vi.unstubAllEnvs()
    }
  })

  it('shows a date it cannot read as it was entered', async () => {
    stubApi({ 'GET /changelog': { body: [entry(1, 'Launch', 'Spring 2026')] } })
    await mountWithApp(ChangelogView, { path: '/changelog' })

    expect(articles()[0]?.date).toBe('Spring 2026')
  })

  // Sanitising is renderMarkdown's contract, pinned in markdown.spec.ts (under
  // jsdom: DOMPurify can't strip handlers in happy-dom).
  it('renders the markdown body', async () => {
    stubApi({
      'GET /changelog': {
        body: [entry(1, 'Passkeys', '2026-10-01', 'Sign in with a **passkey**.')],
      },
    })
    await mountWithApp(ChangelogView, { path: '/changelog' })

    const body = document.body.querySelector('.changelog-body')
    expect(body?.querySelector('strong')?.textContent).toBe('passkey')
  })

  it('says so when there is nothing to show', async () => {
    stubApi({ 'GET /changelog': { body: [] } })
    await mountWithApp(ChangelogView, { path: '/changelog' })

    expect(document.body.textContent).toContain('No changelog entries yet.')
  })

  it("doesn't claim there are no entries before the list has arrived", async () => {
    let answer: (value: Response) => void = () => undefined
    vi.stubGlobal('fetch', () => new Promise<Response>((resolve) => (answer = resolve)))
    await mountWithApp(ChangelogView, { path: '/changelog' })

    expect(document.body.textContent).not.toContain('No changelog entries yet.')

    answer(new Response('[]', { headers: { 'Content-Type': 'application/json' } }))
    await vi.waitFor(() => {
      expect(document.body.textContent).toContain('No changelog entries yet.')
    })
  })

  it('says it could not load the changelog, and why, rather than that it is empty', async () => {
    stubApi({ 'GET /changelog': { status: 503, body: { error: 'Down for maintenance.' } } })
    await mountWithApp(ChangelogView, { path: '/changelog' })

    expect(document.body.textContent).toContain(
      "Couldn't load the changelog. Down for maintenance.",
    )
    expect(document.body.textContent).not.toContain('No changelog entries yet.')
  })
})
