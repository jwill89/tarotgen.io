import { readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

/** Where the smoke run's server listens, and its throwaway data directory. */
export const PORT = 8123
export const BASE_URL = `http://127.0.0.1:${String(PORT)}`
export const DATA_DIR = join(tmpdir(), 'tarotgen-e2e')

/** The seeded admin account (server/prepare.php writes it beside the database). */
export function adminAccount(): { email: string; password: string } {
  return JSON.parse(readFileSync(join(DATA_DIR, 'e2e.json'), 'utf8')) as {
    email: string
    password: string
  }
}
