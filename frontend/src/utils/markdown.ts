import { marked } from 'marked'
import DOMPurify from 'dompurify'

/**
 * Render admin-authored markdown to sanitized HTML.
 */
export function renderMarkdown(md: string | null | undefined): string {
  if (!md) return ''
  const html = marked.parse(md, { async: false })
  return DOMPurify.sanitize(html)
}
