import { describe, it, expect, beforeEach } from 'vitest'
import { useChangelog } from '@/composables/useChangelog'
import { stubApi } from './helpers/app'
import type { ChangelogEntry } from '@/types'

const entry = (entry_id: number, title: string): ChangelogEntry => ({
  entry_id,
  title,
  body: `Notes for ${title}.`,
  entry_date: '2026-10-01',
})

describe('useChangelog', () => {
  beforeEach(() => {
    const state = useChangelog()
    state.entries.value = []
    state.loaded.value = false
    state.error.value = ''
  })

  it('loads the entries from the public changelog', async () => {
    const api = stubApi({ 'GET /changelog': { body: [entry(2, 'Passkeys'), entry(1, 'Launch')] } })
    const { entries, fetchChangelog } = useChangelog()

    await fetchChangelog()

    expect(api).toHaveBeenCalledTimes(1)
    expect(entries.value.map((e) => e.title)).toEqual(['Passkeys', 'Launch'])
  })

  it('keeps what it already showed when a refresh fails', async () => {
    const { entries, fetchChangelog } = useChangelog()
    entries.value = [entry(1, 'Launch')]
    stubApi({ 'GET /changelog': { status: 500, body: { error: 'Database is locked.' } } })

    await fetchChangelog()

    expect(entries.value.map((e) => e.title)).toEqual(['Launch'])
  })

  it("keeps the server's reason when loading fails, and clears it once it loads", async () => {
    const { loaded, error, fetchChangelog } = useChangelog()
    stubApi({ 'GET /changelog': { status: 500, body: { error: 'Database is locked.' } } })

    await fetchChangelog()
    expect([loaded.value, error.value]).toEqual([false, 'Database is locked.'])

    stubApi({ 'GET /changelog': { body: [] } })
    await fetchChangelog()
    expect([loaded.value, error.value]).toEqual([true, ''])
  })
})
