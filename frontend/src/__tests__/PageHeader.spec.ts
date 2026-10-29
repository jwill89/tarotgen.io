import { describe, it, expect, afterEach } from 'vitest'
import PageHeader from '@/components/PageHeader.vue'
import { mountWithApp, cleanup } from './helpers/app'

describe('PageHeader', () => {
  afterEach(cleanup)

  it('gives the page its one top-level heading and an optional subtitle', async () => {
    const { wrapper } = await mountWithApp(PageHeader, {
      props: { title: 'New Draw', subtitle: 'Choose the settings for your draw.' },
    })

    expect(wrapper.findAll('h1').map((h) => h.text())).toEqual(['New Draw'])
    expect(wrapper.get('p').text()).toBe('Choose the settings for your draw.')
  })

  it('leaves out the subtitle and actions when there are none', async () => {
    const { wrapper } = await mountWithApp(PageHeader, { props: { title: 'Reading' } })

    expect(wrapper.find('p').exists()).toBe(false)
    expect(wrapper.find('.page-header__actions').exists()).toBe(false)
  })

  it('shows actions beside the title', async () => {
    const { wrapper } = await mountWithApp(PageHeader, {
      props: { title: 'Reading' },
      slots: { default: '<span>Final</span>' },
    })

    expect(wrapper.get('.page-header__actions').text()).toBe('Final')
  })
})
