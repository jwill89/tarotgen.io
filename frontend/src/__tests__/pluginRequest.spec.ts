import { describe, it, expect } from 'vitest'
import { pluginRequest } from '@/utils/pluginRequest'

const pkce = {
  code_challenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
  code_challenge_method: 'S256',
  redirect_uri: 'http://127.0.0.1:51234/callback',
  state: 'xyz',
}

describe('pluginRequest', () => {
  it('reads the PKCE request the plugin opened the page with', () => {
    expect(pluginRequest(pkce)).toEqual({ params: pkce, valid: true })
  })

  it('takes S256 when no method is named, and an empty state when none is sent', () => {
    expect(
      pluginRequest({ code_challenge: 'abc', redirect_uri: 'http://localhost:5000/cb' }),
    ).toEqual({
      params: {
        code_challenge: 'abc',
        code_challenge_method: 'S256',
        redirect_uri: 'http://localhost:5000/cb',
        state: '',
      },
      valid: true,
    })
  })

  it.each([
    'http://localhost:5000/cb',
    'http://[::1]:5000/cb',
    'http://127.0.0.1/',
    'HTTP://127.0.0.1',
  ])('accepts the loopback redirect %s', (redirect_uri) => {
    expect(pluginRequest({ ...pkce, redirect_uri }).valid).toBe(true)
  })

  it('turns down a page opened with no request at all', () => {
    expect(pluginRequest({})).toEqual({
      params: { code_challenge: '', code_challenge_method: 'S256', redirect_uri: '', state: '' },
      valid: false,
    })
  })

  it.each([
    ['no code challenge', { ...pkce, code_challenge: '' }],
    ['a plain challenge', { ...pkce, code_challenge_method: 'plain' }],
    ['https', { ...pkce, redirect_uri: 'https://127.0.0.1/cb' }],
    ['another host', { ...pkce, redirect_uri: 'http://evil.example/cb' }],
    ['a look-alike host', { ...pkce, redirect_uri: 'http://127.0.0.1.evil.example/' }],
    ['loopback as the userinfo', { ...pkce, redirect_uri: 'http://127.0.0.1@evil.example/' }],
    ['no redirect', { ...pkce, redirect_uri: '' }],
  ])('turns down a request with %s', (_case, query) => {
    expect(pluginRequest(query).valid).toBe(false)
  })
})
