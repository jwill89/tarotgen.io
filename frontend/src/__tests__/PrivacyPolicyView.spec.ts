import { describe, it, expect, afterEach, vi } from 'vitest'
import PrivacyPolicyView from '@/views/PrivacyPolicyView.vue'
import { mountWithApp, cleanup } from './helpers/app'

/**
 * A file elsewhere in the repo. Read through Node, typed by hand: Vite won't
 * serve files outside frontend/, and the app's TypeScript has no Node types.
 */
async function repoFile(path: string): Promise<string> {
  const fs = await vi.importActual<{ readFileSync(path: string, encoding: 'utf8'): string }>(
    'node:fs',
  )
  const url = await vi.importActual<{ fileURLToPath(url: string): string }>('node:url')
  // Not `new URL(…, import.meta.url)` inline: Vite would rewrite that into an asset import.
  const here = import.meta.url
  return fs.readFileSync(url.fileURLToPath(new URL(`../../../${path}`, here).href), 'utf8')
}

const retentionPhp = await repoFile('backend/scripts/retention.php')
const readingInputPhp = await repoFile('backend/includes/Tarot/Service/ReadingInput.php')
const backupScript = await repoFile('scripts/server/tarotgen-backup.sh')

/** The paragraphs under a numbered heading, as one string. */
function section(heading: string): string {
  const h2 = [...document.body.querySelectorAll('h2')].find((h) => h.textContent.includes(heading))
  const parts: string[] = []
  for (let el = h2?.nextElementSibling; el && el.tagName !== 'H2'; el = el.nextElementSibling) {
    parts.push(el.textContent.replace(/\s+/g, ' '))
  }
  return parts.join(' ')
}

// AGENTS.md: the retention window is stated here and enforced by the nightly
// jobs; change both together. These fail when one moves without the other.
describe('PrivacyPolicyView', () => {
  afterEach(cleanup)

  it('states the guest-reading retention that retention.php enforces', async () => {
    const days = Number(/GUEST_READING_DAYS = (\d+);/.exec(retentionPhp)?.[1])
    await mountWithApp(PrivacyPolicyView, { path: '/privacy' })

    expect(days).toBe(365)
    expect(section('Data Retention')).toContain('kept for one year from the day they were created')
  })

  it('states the earlier expiry a guest may choose, as the API offers it', async () => {
    const days = /EXPIRY_DAYS = \[([\d, ]+)\];/.exec(readingInputPhp)?.[1]?.split(', ')
    await mountWithApp(PrivacyPolicyView, { path: '/privacy' })

    expect(days).toEqual(['1', '7', '30'])
    expect(section('Data Retention')).toContain(
      'you can choose to have it deleted sooner: after 1, 7 or 30 days',
    )
  })

  it('states how long the nightly backups keep deleted data', async () => {
    const keep = Number(/TAROT_BACKUP_KEEP:-(\d+)/.exec(backupScript)?.[1])
    await mountWithApp(PrivacyPolicyView, { path: '/privacy' })

    expect(section('Data Retention')).toContain(`backups for up to ${keep} days`)
  })
})
