/**
 * Self-hosted FontAwesome (Pro v7) via the kit npm package + the SVG+JS core.
 *
 * We deliberately register only the icons actually used in the app (extracted
 * from the templates), keeping the bundle tiny. The trade-off: when you use a
 * NEW icon class in a template, add its import here too — otherwise it silently
 * won't render. dom.watch() converts existing <i class="fa-..."> tags to SVG and
 * observes the DOM so icons Vue renders later (and fa-layers composites) work.
 *
 * Icons come from the private kit package (@awesome.me/kit-91d12dd2d3), so there
 * are no CDN requests and no kit pageview/impression metering.
 */
import { library, config, dom } from '@fortawesome/fontawesome-svg-core'
// We import the core CSS ourselves (below), so stop the core from injecting it.
import '@fortawesome/fontawesome-svg-core/styles.css'

// ── Solid (fas) ─────────────────────────────────────────────────────────────
import {
    faAlignCenter, faAlignLeft, faAlignRight,
    faAnglesDown, faAnglesUp,
    faArrowLeft, faArrowRight,
    faArrowsLeftRight, faArrowsRotate, faArrowsToDot, faArrowsUpDown,
    faBars, faBold, faBook, faBookmark, faBroom,
    faCalendarDay, faCards, faCardsBlank, faCartShopping, faCheck,
    faCircleCheck, faCircleExclamation, faCircleInfo, faCirclePlus,
    faCircleQuestion, faCircleUser, faClockRotateLeft, faCode, faCookieBite,
    faCopy, faCrystalBall, faDiamond,
    faEnvelope, faEnvelopeCircleCheck, faEye, faEyeSlash,
    faFloppyDisk, faForward, faGaugeHigh, faGear, faGlobe, faGrid2Plus,
    faHandHoldingMagic, faHandPointer, faHashtag, faHeading, faHouse,
    faImage, faImages, faItalic, faKey, faLayerGroup, faLink,
    faListOl, faListUl, faLock, faLockKeyhole, faLockOpen,
    faMagnifyingGlass, faMagnifyingGlassMinus, faMagnifyingGlassPlus,
    faNewspaper, faPaperPlane, faPen, faPenToSquare, faPlus, faQuoteLeft,
    faRightFromBracket, faRightToBracket, faRotate, faRotateLeft, faRotateRight,
    faScroll, faShuffle, faSparkles, faSpinner, faStar, faSword,
    faTableCells, faTrash, faUnlock, faUser, faUserPlus, faUserShield, faUsers,
    faWandMagicSparkles, faXmark,
} from '@awesome.me/kit-91d12dd2d3/icons/classic/solid'

// ── Duotone solid (fad) ─ aliased to avoid clashing with the solid names above
import {
    faCardsBlank as fadCardsBlank,
    faCircleCheck as fadCircleCheck,
    faCircleExclamation as fadCircleExclamation,
    faCircleQuestion as fadCircleQuestion,
    faClockRotateLeft as fadClockRotateLeft,
    faCrystalBall as fadCrystalBall,
    faEnvelope as fadEnvelope,
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

// The core would otherwise inject its CSS at runtime; we import it statically
// above so it's part of the bundle (no FOUC, no duplicate injection).
config.autoAddCss = false

library.add(
    // solid
    faAlignCenter, faAlignLeft, faAlignRight,
    faAnglesDown, faAnglesUp,
    faArrowLeft, faArrowRight,
    faArrowsLeftRight, faArrowsRotate, faArrowsToDot, faArrowsUpDown,
    faBars, faBold, faBook, faBookmark, faBroom,
    faCalendarDay, faCards, faCardsBlank, faCartShopping, faCheck,
    faCircleCheck, faCircleExclamation, faCircleInfo, faCirclePlus,
    faCircleQuestion, faCircleUser, faClockRotateLeft, faCode, faCookieBite,
    faCopy, faCrystalBall, faDiamond,
    faEnvelope, faEnvelopeCircleCheck, faEye, faEyeSlash,
    faFloppyDisk, faForward, faGaugeHigh, faGear, faGlobe, faGrid2Plus,
    faHandHoldingMagic, faHandPointer, faHashtag, faHeading, faHouse,
    faImage, faImages, faItalic, faKey, faLayerGroup, faLink,
    faListOl, faListUl, faLock, faLockKeyhole, faLockOpen,
    faMagnifyingGlass, faMagnifyingGlassMinus, faMagnifyingGlassPlus,
    faNewspaper, faPaperPlane, faPen, faPenToSquare, faPlus, faQuoteLeft,
    faRightFromBracket, faRightToBracket, faRotate, faRotateLeft, faRotateRight,
    faScroll, faShuffle, faSparkles, faSpinner, faStar, faSword,
    faTableCells, faTrash, faUnlock, faUser, faUserPlus, faUserShield, faUsers,
    faWandMagicSparkles, faXmark,
    // duotone
    fadCardsBlank, fadCircleCheck, fadCircleExclamation, fadCircleQuestion,
    fadClockRotateLeft, fadCrystalBall, fadEnvelope, fadGear, fadGrid2Plus, fadHandHoldingMagic,
    fadLayerGroup, fadNewspaper, fadRightFromBracket, fadScroll, fadScrollOld,
    fadSparkles, fadTableCells, fadUsers,
    // regular
    farStar,
)

// Convert existing <i class="fa-..."> to SVG and observe the DOM for new ones.
dom.watch()
