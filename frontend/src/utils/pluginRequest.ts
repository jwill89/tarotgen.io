import type { LocationQuery } from 'vue-router'
import type { AuthorizeParams } from '@/composables/usePluginLink'

/**
 * Whether a redirect is the plugin's listener on this machine (RFC 8252
 * loopback): the only place a code it asks for may go. Matched as text, as the
 * API matches it; the API refuses any other, so here it only decides whether
 * the page offers anything.
 */
function isLoopbackRedirect(uri: string): boolean {
  return /^http:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?(\/|$)/i.test(uri)
}

/**
 * The PKCE request the plugin opened a page with (the account link's
 * `/authorize`, the draw key's `/draw-key`), and whether it is one to act on:
 * an S256 challenge and a loopback redirect.
 */
export function pluginRequest(query: LocationQuery): { params: AuthorizeParams; valid: boolean } {
  const params: AuthorizeParams = {
    code_challenge: String(query.code_challenge ?? ''),
    code_challenge_method: String(query.code_challenge_method ?? 'S256'),
    redirect_uri: String(query.redirect_uri ?? ''),
    state: String(query.state ?? ''),
  }
  const valid =
    params.code_challenge !== '' &&
    params.code_challenge_method === 'S256' &&
    isLoopbackRedirect(params.redirect_uri)
  return { params, valid }
}
