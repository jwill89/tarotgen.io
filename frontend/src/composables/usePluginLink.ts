import { apiRequest, sendJson } from './apiClient'
import { endpoints } from '@/api/endpoints'
import type { PluginToken } from '@/types'

export interface AuthorizeParams {
  code_challenge: string
  code_challenge_method: string
  redirect_uri: string
  state: string
}

/** Where the browser goes next: the plugin's loopback listener, carrying the code. */
interface CodeResult {
  ok: boolean
  redirectUri?: string
  error?: string
}

/**
 * The Dalamud plugin's browser steps. `authorize` approves an account link and
 * `authorizeDrawKey` (a signed-out page, behind Turnstile) asks for a draw key;
 * each returns the loopback redirect the browser should navigate to.
 * `listTokens`/`revokeToken` back the "Connected Apps" screen.
 */
export function usePluginLink() {
  async function requestCode(path: string, body: object, fallback: string): Promise<CodeResult> {
    const res = await apiRequest<{ redirect_uri?: string }>(path, sendJson('POST', body), fallback)

    if (res.ok && typeof res.data.redirect_uri === 'string') {
      return { ok: true, redirectUri: res.data.redirect_uri }
    }
    return { ok: false, error: res.ok ? 'The server returned an unexpected response.' : res.error }
  }

  function authorize(params: AuthorizeParams): Promise<CodeResult> {
    return requestCode(
      endpoints.plugin.authorize,
      params,
      'Could not authorize the plugin. Please try again.',
    )
  }

  /** The draw key's code, for a person who passed the page's challenge (`turnstileToken`). */
  function authorizeDrawKey(params: AuthorizeParams, turnstileToken: string): Promise<CodeResult> {
    return requestCode(
      endpoints.plugin.drawKeyAuthorize,
      { ...params, turnstile_token: turnstileToken },
      'Could not get a draw key. Please try again.',
    )
  }

  async function listTokens(): Promise<PluginToken[]> {
    const res = await apiRequest<PluginToken[]>(endpoints.account.tokens)
    return res.ok && Array.isArray(res.data) ? res.data : []
  }

  async function revokeToken(id: number): Promise<boolean> {
    const res = await apiRequest(endpoints.account.tokenById(id), { method: 'DELETE' })
    return res.ok
  }

  return { authorize, authorizeDrawKey, listTokens, revokeToken }
}
