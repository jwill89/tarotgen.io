import { describe, it, expect } from 'vitest'
import { dateOf, isKept, localDay, monthCalendar, monthWeeks, shiftMonth } from '@/utils/calendar'
import type { DailyCard } from '@/types'

const kept: DailyCard = {
  day: '2026-10-03',
  reading_id: 'r1',
  deck_id: 1,
  deck_name: 'Rider-Waite',
  card_id: 17,
  card_name: 'The Star',
  reversed: false,
}
/** A day drawn whose reading was deleted since, as the API sends it. */
const deleted: DailyCard = {
  day: '2026-10-12',
  reading_id: null,
  deck_id: null,
  deck_name: null,
  card_id: null,
  card_name: null,
  reversed: null,
}

describe('calendar', () => {
  it("names a moment's local date", () => {
    expect(localDay(new Date(2026, 9, 30, 23, 59))).toBe('2026-10-30')
    expect(localDay(new Date(2026, 0, 5, 0, 0))).toBe('2026-01-05')
  })

  it('reads a day or a month back as a local midnight', () => {
    expect(dateOf('2026-10-30')).toEqual(new Date(2026, 9, 30))
    expect(dateOf('2026-10')).toEqual(new Date(2026, 9, 1))
  })

  it('steps months across the turn of a year', () => {
    expect(shiftMonth('2026-10', -1)).toBe('2026-09')
    expect(shiftMonth('2026-01', -1)).toBe('2025-12')
    expect(shiftMonth('2025-12', 1)).toBe('2026-01')
    expect(shiftMonth('2026-10', 14)).toBe('2027-12')
  })

  it('lays a month out in Sunday-first weeks, padded with nulls', () => {
    const october = monthWeeks('2026-10')

    expect(october).toHaveLength(5)
    expect(october.every((week) => week.length === 7)).toBe(true)
    // 1 October 2026 is a Thursday.
    expect(october[0]).toEqual([null, null, null, null, '2026-10-01', '2026-10-02', '2026-10-03'])
    expect(october[4]).toEqual([
      '2026-10-25',
      '2026-10-26',
      '2026-10-27',
      '2026-10-28',
      '2026-10-29',
      '2026-10-30',
      '2026-10-31',
    ])
  })

  it('fits a February that starts on a Sunday into four weeks', () => {
    const february = monthWeeks('2026-02')

    expect(february).toHaveLength(4)
    expect(february[0]?.[0]).toBe('2026-02-01')
    expect(february[3]?.[6]).toBe('2026-02-28')
  })

  it('tells a daily card from a day whose reading was deleted', () => {
    expect(isKept(kept)).toBe(true)
    expect(isKept(deleted)).toBe(false)
  })

  it('gives each day of a month its daily card, a deleted one drawn with nothing to open', () => {
    const october = monthCalendar('2026-10', [kept, deleted, { ...kept, day: '2026-09-30' }])

    expect(october.map((week) => week.map((cell) => cell?.day ?? null))).toEqual(
      monthWeeks('2026-10'),
    )
    const days = october.flat().filter((cell) => cell !== null)
    expect(days.find((d) => d.day === '2026-10-03')).toEqual({
      day: '2026-10-03',
      status: 'drawn',
      card: kept,
    })
    expect(days.find((d) => d.day === '2026-10-12')).toEqual({
      day: '2026-10-12',
      status: 'deleted',
    })
    // Another month's card stays out; every other day is empty.
    expect(days.filter((d) => d.status === 'empty')).toHaveLength(29)
  })
})
