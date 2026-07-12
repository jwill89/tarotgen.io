/**
 * Self-hosted FontAwesome (Pro v7) wired for use with the Vue component
 * (`@fortawesome/vue-fontawesome`).
 *
 * We deliberately register only the icons actually used in the app, keeping the
 * bundle tiny. Each icon is imported by name (tree-shakeable) and collected into
 * a `byPrefixAndName` lookup keyed by FA prefix + icon name, e.g.
 *
 *     <FontAwesomeIcon :icon="byPrefixAndName.fad['cards-blank']" />
 *
 * The trade-off: when you use a NEW icon in a template, add its import below and
 * include it in `index(...)` — otherwise the lookup is `undefined` and it won't
 * render. (We intentionally do NOT import the kit's own `byPrefixAndName`, which
 * pulls in the entire icon set and would balloon the bundle by tens of MB.)
 *
 * Icons come from the private kit package (@awesome.me/kit-91d12dd2d3), so there
 * are no CDN requests and no kit pageview/impression metering.
 */
import { config } from '@fortawesome/fontawesome-svg-core'
import type { IconDefinition, IconPrefix } from '@fortawesome/fontawesome-svg-core'
// We import the core CSS ourselves so it's part of the bundle (sizing, spin
// keyframes, fa-layers, etc.). config.autoAddCss=false stops the runtime inject.
import '@fortawesome/fontawesome-svg-core/styles.css'

// ── Solid (fas) ─────────────────────────────────────────────────────────────
import {
  faAlignCenter,
  faAlignLeft,
  faAlignRight,
  faAnglesDown,
  faAnglesUp,
  faArrowLeft,
  faArrowRight,
  faArrowsLeftRight,
  faArrowsRotate,
  faArrowsToDot,
  faArrowsUpDown,
  faBars,
  faBold,
  faBook,
  faBookmark,
  faBroom,
  faCalendarDay,
  faCards,
  faCardsBlank,
  faCartShopping,
  faCheck,
  faChevronDown,
  faChevronRight,
  faChevronUp,
  faCircleCheck,
  faCircleExclamation,
  faCircleInfo,
  faCirclePlus,
  faCircleQuestion,
  faCircleUser,
  faClockRotateLeft,
  faCode,
  faCookieBite,
  faCopy,
  faCrystalBall,
  faDiamond,
  faEnvelope,
  faEnvelopeCircleCheck,
  faEnvelopeOpen,
  faEye,
  faEyeSlash,
  faFloppyDisk,
  faFont,
  faForward,
  faGamepad,
  faGaugeHigh,
  faGear,
  faGlobe,
  faGrid2Plus,
  faHandHoldingMagic,
  faHandPointer,
  faHashtag,
  faHeading,
  faHouse,
  faImage,
  faImages,
  faItalic,
  faKey,
  faLayerGroup,
  faLink,
  faListOl,
  faListUl,
  faLock,
  faLockKeyhole,
  faLockOpen,
  faMagnifyingGlass,
  faMagnifyingGlassMinus,
  faMagnifyingGlassPlus,
  faNewspaper,
  faPaperPlane,
  faPen,
  faPenToSquare,
  faPlus,
  faQuoteLeft,
  faRightFromBracket,
  faRightToBracket,
  faRotate,
  faRotateLeft,
  faRotateRight,
  faScroll,
  faShuffle,
  faSort,
  faSortDown,
  faSortUp,
  faSparkles,
  faSpinner,
  faStar,
  faSword,
  faTableCells,
  faTrash,
  faTriangleExclamation,
  faUnlock,
  faUser,
  faUserPlus,
  faUserShield,
  faUsers,
  faWandMagicSparkles,
  faXmark,
} from '@awesome.me/kit-91d12dd2d3/icons/classic/solid'

// ── Duotone solid (fad) ─────────────────────────────────────────────────────
import {
  faCardsBlank as fadCardsBlank,
  faCircleCheck as fadCircleCheck,
  faCircleExclamation as fadCircleExclamation,
  faCircleQuestion as fadCircleQuestion,
  faClockRotateLeft as fadClockRotateLeft,
  faCrystalBall as fadCrystalBall,
  faEnvelope as fadEnvelope,
  faGamepad as fadGamepad,
  faGear as fadGear,
  faGrid2Plus as fadGrid2Plus,
  faHandHoldingMagic as fadHandHoldingMagic,
  faLayerGroup as fadLayerGroup,
  faNewspaper as fadNewspaper,
  faRightFromBracket as fadRightFromBracket,
  faScroll as fadScroll,
  faScrollOld as fadScrollOld,
  faSparkles as fadSparkles,
  faTableCells as fadTableCells,
  faUsers as fadUsers,
} from '@awesome.me/kit-91d12dd2d3/icons/duotone/solid'

// ── Regular (far) ───────────────────────────────────────────────────────────
import { faStar as farStar } from '@awesome.me/kit-91d12dd2d3/icons/classic/regular'

// ── Brands (fab) ────────────────────────────────────────────────────────────
import { faGoogle } from '@awesome.me/kit-91d12dd2d3/icons/classic/brands'

// The core would otherwise inject its CSS at runtime; we import it statically
// above so it's part of the bundle (no FOUC, no duplicate injection).
config.autoAddCss = false

/**
 * Index the imported icon definitions into `{ [prefix]: { [iconName]: def } }`.
 * Each IconDefinition already carries its own `prefix` and `iconName`, so we
 * don't have to hand-maintain the kebab-case names.
 */
function index(defs: IconDefinition[]): Record<IconPrefix, Record<string, IconDefinition>> {
  // Built up incrementally, so buckets are genuinely absent until first seen.
  const map: Partial<Record<IconPrefix, Record<string, IconDefinition>>> = {}
  for (const def of defs) {
    ;(map[def.prefix] ??= {})[def.iconName] = def
  }
  return map as Record<IconPrefix, Record<string, IconDefinition>>
}

export const byPrefixAndName = index([
  // solid (fas)
  faAlignCenter,
  faAlignLeft,
  faAlignRight,
  faAnglesDown,
  faAnglesUp,
  faArrowLeft,
  faArrowRight,
  faArrowsLeftRight,
  faArrowsRotate,
  faArrowsToDot,
  faArrowsUpDown,
  faBars,
  faBold,
  faBook,
  faBookmark,
  faBroom,
  faCalendarDay,
  faCards,
  faCardsBlank,
  faCartShopping,
  faCheck,
  faChevronDown,
  faChevronRight,
  faChevronUp,
  faCircleCheck,
  faCircleExclamation,
  faCircleInfo,
  faCirclePlus,
  faCircleQuestion,
  faCircleUser,
  faClockRotateLeft,
  faCode,
  faCookieBite,
  faCopy,
  faCrystalBall,
  faDiamond,
  faEnvelope,
  faEnvelopeCircleCheck,
  faEnvelopeOpen,
  faEye,
  faEyeSlash,
  faFloppyDisk,
  faFont,
  faForward,
  faGamepad,
  faGaugeHigh,
  faGear,
  faGlobe,
  faGrid2Plus,
  faHandHoldingMagic,
  faHandPointer,
  faHashtag,
  faHeading,
  faHouse,
  faImage,
  faImages,
  faItalic,
  faKey,
  faLayerGroup,
  faLink,
  faListOl,
  faListUl,
  faLock,
  faLockKeyhole,
  faLockOpen,
  faMagnifyingGlass,
  faMagnifyingGlassMinus,
  faMagnifyingGlassPlus,
  faNewspaper,
  faPaperPlane,
  faPen,
  faPenToSquare,
  faPlus,
  faQuoteLeft,
  faRightFromBracket,
  faRightToBracket,
  faRotate,
  faRotateLeft,
  faRotateRight,
  faScroll,
  faShuffle,
  faSort,
  faSortDown,
  faSortUp,
  faSparkles,
  faSpinner,
  faStar,
  faSword,
  faTableCells,
  faTrash,
  faTriangleExclamation,
  faUnlock,
  faUser,
  faUserPlus,
  faUserShield,
  faUsers,
  faWandMagicSparkles,
  faXmark,
  // duotone (fad)
  fadCardsBlank,
  fadCircleCheck,
  fadCircleExclamation,
  fadCircleQuestion,
  fadClockRotateLeft,
  fadCrystalBall,
  fadEnvelope,
  fadGamepad,
  fadGear,
  fadGrid2Plus,
  fadHandHoldingMagic,
  fadLayerGroup,
  fadNewspaper,
  fadRightFromBracket,
  fadScroll,
  fadScrollOld,
  fadSparkles,
  fadTableCells,
  fadUsers,
  // regular (far)
  farStar,
  // brands (fab)
  faGoogle,
])
