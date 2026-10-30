import { describe, it, expect } from 'vitest'
import { normalizeTag, visibilityHelp, visibilityLabels } from '@/utils/visibility'

describe('normalizeTag', () => {
  it.each([
    ['Career', 'career'],
    ['  new \t moon ', 'new moon'],
    ['##Monthly', 'monthly'],
    ['c#', 'c#'],
    ['love\u0007life', 'love life'],
    ['#', ''],
  ])('keeps %j as %j, as the server does', (raw, tag) => {
    expect(normalizeTag(raw)).toBe(tag)
  })
})

describe('visibility words', () => {
  it('names and explains each choice', () => {
    expect(Object.keys(visibilityLabels)).toEqual(['link', 'password', 'private'])
    expect(Object.keys(visibilityHelp)).toEqual(['link', 'password', 'private'])
    expect(visibilityLabels.private).toBe('Private')
  })
})
