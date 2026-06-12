/**
 * Heading font switcher.
 *
 * Lets us audition different display fonts for the app's headings without a
 * rebuild: every heading style reads the `--myst-heading-font` CSS variable
 * (see tokens.css / style.css), and this composable swaps that variable on
 * <html>, persisting the choice so it survives reloads.
 *
 * To add another candidate later: drop the file in `public/fonts/`, add an
 * `@font-face` to `src/assets/fonts.css`, then add an entry to HEADING_FONTS.
 */
import { ref } from 'vue'
import { STORAGE_KEYS } from '@/constants'

export interface HeadingFont {
    /** Stable id persisted to storage. */
    id: string
    /** Human label shown in the switcher. */
    label: string
    /** Full CSS font-family stack (with fallbacks). */
    stack: string
}

/** Available heading fonts. `cinzel` is the default (loaded via Google Fonts). */
export const HEADING_FONTS: readonly HeadingFont[] = [
    { id: 'cinzel', label: 'Cinzel', stack: '"Cinzel", Georgia, "Times New Roman", serif' },
    { id: 'against', label: 'Against', stack: '"Against", Georgia, "Times New Roman", serif' },
    { id: 'belgiano', label: 'Belgiano Serif', stack: '"Belgiano Serif", Georgia, "Times New Roman", serif' },
    { id: 'tarotheque', label: 'Tarotheque', stack: '"Tarotheque", Georgia, "Times New Roman", serif' },
] as const

const DEFAULT_FONT_ID = 'cinzel'

function resolveFont(id: string | null): HeadingFont {
    return HEADING_FONTS.find((f) => f.id === id) ?? HEADING_FONTS[0]
}

// Module-scoped so every caller shares one source of truth.
const currentFontId = ref<string>(DEFAULT_FONT_ID)

/** Write the active stack to the CSS variable that all heading styles read. */
function applyToDocument(font: HeadingFont): void {
    document.documentElement.style.setProperty('--myst-heading-font', font.stack)
}

/**
 * Read the saved choice and apply it. Call once before mount (in main.ts) so
 * the chosen font paints on the first frame instead of flashing the default.
 */
export function initHeadingFont(): void {
    let saved: string | null = null
    try {
        saved = localStorage.getItem(STORAGE_KEYS.headingFont)
    } catch {
        // Storage may be unavailable (private mode); fall back to the default.
    }
    const font = resolveFont(saved)
    currentFontId.value = font.id
    applyToDocument(font)
}

export function useHeadingFont() {
    function setHeadingFont(id: string): void {
        const font = resolveFont(id)
        currentFontId.value = font.id
        applyToDocument(font)
        try {
            localStorage.setItem(STORAGE_KEYS.headingFont, font.id)
        } catch {
            // Persisting is best-effort; the in-memory choice still applies.
        }
    }

    return { fonts: HEADING_FONTS, currentFontId, setHeadingFont }
}

