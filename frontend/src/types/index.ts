import type { components } from './api.generated'

type Schemas = components['schemas']

/*
 * Entities the API sends exactly as a backend Structure come from the spec
 * (`npm run gen:types` regenerates api.generated.ts from backend/openapi.json,
 * and CI fails if it is stale), so a field added or retyped in PHP reaches the
 * frontend without a hand copy to update. Payloads a controller assembles itself
 * (a reading as viewed, the account's reading list, the stats overview) and
 * client-side shapes are written by hand further down.
 */
export type User = Schemas['User']
export type PluginToken = Schemas['PluginToken']
export type Deck = Schemas['Deck']
export type DeckSystem = Schemas['DeckSystem']
export type DeckSystemCard = Schemas['DeckSystemCard']
export type SpecialCard = Schemas['SpecialCard']
export type SpreadPosition = Schemas['SpreadPosition']
export type Spread = Schemas['Spread']
export type PendingSpread = Schemas['PendingSpread']
export type UserSpread = Schemas['UserSpread']
export type ChangelogEntry = Schemas['ChangelogEntry']
export type Contact = Schemas['Contact']
export type CardReportReason = Schemas['CardReportReason']
export type CardReportSubmission = Schemas['CardReportSubmission']
export type CardReport = Schemas['CardReport']

export interface DeckSystemWithCards extends DeckSystem {
  cards: DeckSystemCard[]
}

/** A selectable card within a deck (id + display name) for the custom reading picker. */
export interface DeckCard {
  card_id: number
  name: string
}

/** Snapshot of a spread stored within a saved reading. */
export type SpreadSnapshot = Pick<Spread, 'spread_id' | 'name' | 'description' | 'positions'>

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

/** URLs come from `cardImageUrls()` (utils/cardImages). */
export interface ReadingCard extends DrawCard {
  /** Full-size display WebP (lightbox, export). */
  imgUrl: string
  /** Master PNG scan — the fallback while a deck's web images aren't generated. */
  pngUrl: string
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
