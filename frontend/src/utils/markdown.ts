import { marked } from 'marked'
import DOMPurify from 'dompurify'

/**
 * Render markdown to sanitized HTML.
 *
 * Used for BOTH admin-authored copy (changelog entries, spread descriptions) and
 * user-supplied content (contact messages, reading notes, user spread
 * descriptions), so the DOMPurify pass is load-bearing, not cosmetic: it is the
 * only thing that makes the `v-html` call sites safe. Every non-empty path goes
 * through it — keep it that way.
 */
export function renderMarkdown(md: string | null | undefined): string {
  if (!md) return ''
  const html = marked.parse(md, { async: false })
  return DOMPurify.sanitize(html)
}
