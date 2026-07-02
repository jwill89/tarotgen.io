import { describe, it, expect, beforeEach, vi } from 'vitest'
import { local, session } from '@/utils/storage'

describe('storage', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

  it('round-trips JSON-serialisable values', () => {
    local.set('prefs', { theme: 'dark', count: 3 })
    expect(local.get('prefs', null)).toEqual({ theme: 'dark', count: 3 })
  })

  it('returns the fallback when the key is absent', () => {
    expect(local.get('missing', 'default')).toBe('default')
  })

  it('returns the fallback when the stored value is malformed JSON', () => {
    localStorage.setItem('broken', '{not valid json')
    expect(local.get('broken', 'fallback')).toBe('fallback')
  })

  it('removes a key', () => {
    local.set('temp', 1)
    local.remove('temp')
    expect(local.get('temp', 'gone')).toBe('gone')
  })

  it('degrades gracefully when reading throws (e.g. storage disabled)', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('SecurityError')
    })
    expect(local.get('anything', 'safe')).toBe('safe')
  })

  it('swallows write errors instead of throwing (e.g. quota exceeded)', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceededError')
    })
    expect(() => local.set('big', 'x')).not.toThrow()
  })

  it('exposes an independent session store', () => {
    session.set('s', 'value')
    expect(session.get('s', null)).toBe('value')
    // Writing to session does not leak into local.
    expect(local.get('s', 'none')).toBe('none')
  })
})
