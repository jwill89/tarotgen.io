import { apiRequest } from './apiClient'
import { endpoints } from '@/api/endpoints'
import type { PluginToken } from '@/types'

const JSON_HEADERS = { 'Content-Type': 'application/json' }

export interface AuthorizeParams {
  code_challenge: string
  code_challenge_method: string
  redirect_uri: string
  state: string
}

/**
 * Dalamud plugin account linking (browser side). `authorize` approves a link
 * request and returns the loopback redirect the browser should navigate to;
 * `listTokens`/`revokeToken` back the "Connected Apps" screen.
 */
export function usePluginLink() {
  async function authorize(
    params: AuthorizeParams,
  ): Promise<{ ok: boolean; redirectUri?: string; error?: string }> {
    const res = await apiRequest<{ redirect_uri?: string }>(
      endpoints.plugin.authorize,
      { method: 'POST', headers: JSON_HEADERS, body: JSON.stringify(params) },
      'Could not authorize the plugin. Please try again.',
    )

    if (res.ok && typeof res.data.redirect_uri === 'string') {
      return { ok: true, redirectUri: res.data.redirect_uri }
    }
    return { ok: false, error: res.ok ? 'The server returned an unexpected response.' : res.error }
  }

  async function listTokens(): Promise<PluginToken[]> {
    const res = await apiRequest<PluginToken[]>(endpoints.account.tokens)
    return res.ok && Array.isArray(res.data) ? res.data : []
  }

  async function revokeToken(id: number): Promise<boolean> {
    const res = await apiRequest(endpoints.account.tokenById(id), { method: 'DELETE' })
    return res.ok
  }

  return { authorize, listTokens, revokeToken }
}
