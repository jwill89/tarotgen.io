/** A logged-in user account, as returned by the /user endpoints (no secrets). */
export interface User {
    user_id: number
    email: string
    display_name: string
    is_active: boolean
    is_admin: boolean
    registered_at: string
    last_login_at: string | null
    google_linked: boolean
    password_login_disabled: boolean
    has_passkeys: boolean
}


export interface Deck {
    deck_id: number
    deck_system_id: number
    name: string
    artist: string
    purchase_url: string
    additional_cards: number
    /** Card slot aspect ratio (width / height components); defaults 5 × 8.6. */
    card_aspect_w: number
    card_aspect_h: number
    approved: boolean
    usable: boolean
    submitted_by: number | null
    /** Deck system short name (populated by JOIN). */
    system_short_name: string
    /** Deck system total cards (populated by JOIN). */
    system_total_cards: number
}

export interface DeckSystem {
    deck_system_id: number
    name: string
    short_name: string
    total_cards: number
    approved: boolean
    submitted_by: number | null
}

export interface DeckSystemCard {
    deck_system_id: number
    card_id: number
    name: string
    keywords: string | null
    meaning: string | null
    advice: string | null
    reversed_keywords: string | null
    reversed_meaning: string | null
    reversed_advice: string | null
}

export interface DeckSystemWithCards extends DeckSystem {
    cards: DeckSystemCard[]
}

export interface SpecialCard {
    deck_id: number
    card_id: number
    name: string
    keywords: string | null
    meaning: string | null
    advice: string | null
    keywords_reversed: string | null
    meaning_reversed: string | null
    advice_reversed: string | null
}

/** A selectable card within a deck (id + display name) for the custom reading picker. */
export interface DeckCard {
    card_id: number
    name: string
}

export interface SpreadPosition {
    order: number
    title: string
    x: number
    y: number
    rotation: number
}

export interface Spread {
    spread_id: number
    name: string
    description: string
    card_count: number
    positions: SpreadPosition[]
}

/** Snapshot of a spread stored within a saved reading. */
export type SpreadSnapshot = Pick<Spread, 'spread_id' | 'name' | 'description' | 'positions'>

/** A user-submitted spread awaiting admin approval. */
export interface PendingSpread {
    pending_id: number
    name: string
    description: string
    card_count: number
    positions: SpreadPosition[]
    /** Resolved submitter label: the account's display name, or "Guest". */
    submitter: string
    user_id: number | null
    submitted_at: string
}

/** A user's personal/private spread. */
export interface UserSpread {
    user_spread_id: number
    user_id: number
    name: string
    description: string
    card_count: number
    positions: SpreadPosition[]
    created_at: string
    updated_at: string
}

/** Union type used by the spread selector to display both public and user spreads. */
export interface SpreadOption {
    id: string // e.g. "public-3" or "user-12"
    spread_id?: number
    user_spread_id?: number
    name: string
    description: string
    card_count: number
    positions: SpreadPosition[]
    type: 'public' | 'personal'
    isFavorite?: boolean
}

export interface ChangelogEntry {
    entry_id: number
    title: string
    body: string
    entry_date: string
}

export interface Reading {
    reading_id: string
    reading_info: ReadingInfo
    reading_time: string
    /** Optional custom title; falls back to "Your Reading" when null. */
    reading_name?: string | null
    /** Optional Markdown reading notes (detailed interpretation). */
    reading_notes?: string | null
    /** Resolved author label for the details box ("Guest" or a display name). */
    reader?: string
    /** Whether the current viewer owns this reading. */
    is_owner?: boolean
    /** True once the owner has locked the reading against further draws. */
    is_final?: boolean
    /** True when the viewer (owner) may draw additional cards into this reading. */
    can_draw_more?: boolean
    /** True (with no reading_info) when the reading is password-locked for this viewer. */
    locked?: boolean
}

/** Summary of one of the current user's readings (account dashboard). */
export interface AccountReading {
    reading_id: string
    reading_info: ReadingInfo
    reading_time: string
    reading_name: string | null
    reading_notes: string | null
    hide_user: boolean
    password_protected: boolean
}

export interface ReadingInfo {
    deck_id: number
    draw: DrawCard[]
    spread?: SpreadSnapshot | null
    /** How the reading was created. Custom readings can't draw additional cards. */
    origin?: 'generated' | 'custom'
}

export interface DrawCard {
    card_id: number
    reversed: boolean
    card_name: string
}

export interface ReadingCard extends DrawCard {
    imgUrl: string
    thumbUrl: string
}

/** Admin dashboard usage analytics. */
export interface UsageStats {
    totals: { readings: number; last7: number; last30: number }
    byType: { freeDraw: number; spread: number; custom: number }
    topDecks: { deck_id: number; name: string; count: number }[]
    topSpreads: { name: string; count: number }[]
    daily: { date: string; count: number }[]
}

/** A contact form submission visible in the admin panel. */
export interface Contact {
    contact_id: number
    user_id: number | null
    name: string
    email: string
    message: string
    is_read: number
    submitted_at: string
}
