import { readFileSync } from 'node:fs'
import type { Plugin } from 'vite'
import { parseChangelog } from '../src/utils/changelog.ts'

const MODULE_ID = 'virtual:changelog'
const RESOLVED_ID = '\0' + MODULE_ID

/**
 * Serves CHANGELOG.md, parsed, as `import { releases } from 'virtual:changelog'`
 * (typed in src/vite-env.d.ts): the admin dashboard's changelog dialog, built
 * into the bundle, so it needs no API call and no generated file to commit.
 *
 * The file is the repo root's, outside the frontend project, so a build needs
 * the whole repo checked out (CI checks it all out). A missing file fails the
 * build rather than shipping an empty changelog. It is read on every load and
 * watched, so `vite dev` picks up an edit.
 */
export function changelogPlugin(changelogPath: string): Plugin {
  return {
    name: 'tarot-changelog',
    resolveId(id) {
      return id === MODULE_ID ? RESOLVED_ID : undefined
    },
    load(id) {
      if (id !== RESOLVED_ID) {
        return undefined
      }
      this.addWatchFile(changelogPath)
      const releases = parseChangelog(readFileSync(changelogPath, 'utf8'))
      return `export const releases = ${JSON.stringify(releases)}\n`
    },
  }
}
