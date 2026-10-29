import { describe, it, expect, afterEach } from 'vitest'
import TermsOfServiceView from '@/views/TermsOfServiceView.vue'
import { mountWithApp, cleanup } from './helpers/app'

describe('TermsOfServiceView', () => {
  afterEach(cleanup)

  it('points readers to the privacy policy and the contact form', async () => {
    await mountWithApp(TermsOfServiceView, { path: '/terms' })

    const links = [...document.body.querySelectorAll('a')].map((a) => [
      a.textContent.trim(),
      a.getAttribute('href'),
    ])
    expect(links).toEqual(
      expect.arrayContaining([
        ['Privacy Policy', '/privacy'],
        ['contact form', '/contact'],
      ]),
    )
  })
})
