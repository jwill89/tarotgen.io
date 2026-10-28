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
export type Passkey = Schemas['Passkey']
export type PluginToken = Schemas['PluginToken']
export type Deck = Schemas['Deck']
export type DeckSystem = Schemas['DeckSystem']
export type DeckSystemCard = Schemas['DeckSystemCard']
export type SpecialCard = Schemas['SpecialCard']
export type SpreadPosition = Schemas['SpreadPosition']
export type Spread = Schemas['Spread']
export type PendingSpread = Schemas['PendingSpread']
export type UserSpread = Schemas['UserSpread']
export type FavoriteSpread = Schemas['FavoriteSpread']
export type ChangelogEntry = Schemas['ChangelogEntry']
export type Contact = Schemas['Contact']
export type CardReportReason = Schemas['CardReportReason']
export type CardReportSubmission = Schemas['CardReportSubmission']
export type CardReport = Schemas['CardReport']

// Readings. `Reading` is a reading as its viewer sees it (GET /readings/{id} and
// the reading commands); `LockedReading` is what that GET answers instead while
// a password stands in the way; `AccountReading` is the stored row the account
// pages list; `ReadingSummary` is a row of the admin readings list.
export type Reading = Schemas['ReadingView']
export type LockedReading = Schemas['LockedReading']
export type AccountReading = Schemas['Reading']
export type ReadingSummary = Schemas['ReadingSummary']
export type ReadingInfo = Schemas['ReadingInfo']
export type DrawCard = Schemas['DrawCard']
export type SpreadSnapshot = Schemas['SpreadSnapshot']

export interface DeckSystemWithCards extends DeckSystem {
  cards: DeckSystemCard[]
}

/** A selectable card within a deck (id + display name) for the custom reading picker. */
export interface DeckCard {
  card_id: number
  name: string
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

/**
 * A drawn card ready to show. URLs come from `cardImageUrls()` (utils/cardImages);
 * `card_name` is always set — `cardLabel()` (utils/deck) when the deck has none.
 */
export interface ReadingCard extends DrawCard {
  card_name: string
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
