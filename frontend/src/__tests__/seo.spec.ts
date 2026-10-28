import { describe, it, expect, beforeEach } from 'vitest'
import { applyRouteSeo } from '@/utils/seo'

const meta = (name: string) =>
  document.head.querySelector(`meta[name="${name}"]`)?.getAttribute('content')
const canonical = () => document.head.querySelector('link[rel="canonical"]')?.getAttribute('href')

describe('applyRouteSeo', () => {
  beforeEach(() => {
    document.head.innerHTML = ''
  })

  it("sets the route's description, a self-referencing canonical and indexing", () => {
    applyRouteSeo({ description: 'Browse the decks.' }, '/decks')

    expect(meta('description')).toBe('Browse the decks.')
    expect(canonical()).toBe('https://tarotgen.io/decks')
    expect(meta('robots')).toBe('index, follow')
  })

  it('falls back to the site description', () => {
    applyRouteSeo({}, '/')

    expect(meta('description')).toMatch(/^Draw your own tarot spreads!/)
    expect(canonical()).toBe('https://tarotgen.io/')
  })

  it('drops trailing slashes from the canonical, except for the home page', () => {
    applyRouteSeo({}, '/spreads//')

    expect(canonical()).toBe('https://tarotgen.io/spreads')
  })

  it('keeps noindex routes out of search', () => {
    applyRouteSeo({ noindex: true }, '/account')

    expect(meta('robots')).toBe('noindex, nofollow')
  })

  it('updates the existing tags on the next navigation instead of adding more', () => {
    applyRouteSeo({ description: 'First.', noindex: true }, '/account')
    applyRouteSeo({ description: 'Second.' }, '/decks')

    expect(document.head.querySelectorAll('meta[name="description"]')).toHaveLength(1)
    expect(document.head.querySelectorAll('link[rel="canonical"]')).toHaveLength(1)
    expect(meta('description')).toBe('Second.')
    expect(meta('robots')).toBe('index, follow')
  })
})
