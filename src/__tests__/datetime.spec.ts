import { describe, it, expect } from 'vitest'
import { formatDateTime } from '@/utils/datetime'

// The suite pins TZ=UTC (see vitest.setup.ts), so local-time output is stable.
describe('formatDateTime', () => {
    it('returns an empty string for empty input', () => {
        expect(formatDateTime('')).toBe('')
        expect(formatDateTime(null)).toBe('')
        expect(formatDateTime(undefined)).toBe('')
    })

    it('returns the raw value when it cannot be parsed', () => {
        expect(formatDateTime('not a date')).toBe('not a date')
    })

    it('treats a zone-less SQLite timestamp as UTC', () => {
        // A naive timestamp and the same instant marked UTC must format
        // identically — proving the "append Z" normalisation works.
        const naive = formatDateTime('2025-06-10 23:30:00')
        const utc = formatDateTime('2025-06-10T23:30:00Z')
        expect(naive).toBe(utc)
    })

    it('honours an explicit UTC offset instead of appending Z', () => {
        expect(formatDateTime('2025-06-10T23:30:00+00:00'))
            .toBe(formatDateTime('2025-06-10T23:30:00Z'))
    })

    it('formats the date portion as YYYY-MM-DD', () => {
        // Under TZ=UTC this instant stays on the 15th.
        expect(formatDateTime('2025-01-15 12:00:00')).toMatch(/^2025-01-15 /)
    })

    it('zero-pads single-digit months and days', () => {
        expect(formatDateTime('2025-03-05 08:00:00')).toMatch(/^2025-03-05 /)
    })
})
