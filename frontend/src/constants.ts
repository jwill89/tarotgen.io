/**
 * Shared application constants. Centralised so values used in more than one
 * place (e.g. storage keys) can't silently drift apart.
 */

// Single source of truth for the app version. Vite resolves this JSON import and
// tree-shakes it down to just the version string in the build.
import { version } from '../package.json'

/** The application version (from package.json). Surfaced on the admin dashboard. */
export const APP_VERSION: string = version

/** Web Storage keys. Namespaced under `tarot.` to avoid collisions. */
export const STORAGE_KEYS = {
  /** Remembers the deck last chosen on the reading screens. */
  lastDeck: 'tarot.newReading.deckId',
  /** List of the visitor's recently generated/viewed readings. */
  recentReadings: 'tarot.recentReadings',
  /** Cached current user (display name, is_admin, etc.) for instant nav/guards on reload. */
  currentUser: 'tarot.user.current',
  /** Chosen heading/display font (id from HEADING_FONTS). */
  headingFont: 'tarot.ui.headingFont',
} as const
