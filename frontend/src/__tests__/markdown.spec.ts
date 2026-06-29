// @vitest-environment jsdom
// DOMPurify (>=3.4.8) sanitizes correctly in real browsers and jsdom, but the
// default happy-dom test DOM (even at its latest) mishandles its parsing and
// fails to strip <script> — so this XSS-contract test runs under jsdom.
import { describe, it, expect } from 'vitest'
import { renderMarkdown } from '@/utils/markdown'

describe('renderMarkdown', () => {
    it('returns an empty string for empty input', () => {
        expect(renderMarkdown('')).toBe('')
        expect(renderMarkdown(null)).toBe('')
        expect(renderMarkdown(undefined)).toBe('')
    })

    it('renders basic markdown to HTML', () => {
        const html = renderMarkdown('**bold** and *italic*')
        expect(html).toContain('<strong>bold</strong>')
        expect(html).toContain('<em>italic</em>')
    })

    it('renders links', () => {
        const html = renderMarkdown('[site](https://example.com)')
        expect(html).toContain('href="https://example.com"')
    })

    // Documents the security contract: admin-authored markdown is sanitised
    // before it ever reaches the DOM.
    it('strips script tags (XSS sanitisation)', () => {
        const html = renderMarkdown('hello <script>alert(1)</script> world')
        expect(html).not.toContain('<script>')
        expect(html).not.toContain('alert(1)')
    })

    it('strips inline event handlers', () => {
        const html = renderMarkdown('<img src="x" onerror="alert(1)">')
        expect(html.toLowerCase()).not.toContain('onerror')
    })
})
