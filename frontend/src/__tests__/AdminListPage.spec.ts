import { describe, it, expect, afterEach } from 'vitest'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import { mountWithApp, cleanup } from './helpers/app'

describe('AdminListPage', () => {
  afterEach(cleanup)

  it('titles the page and holds its content', async () => {
    const { wrapper } = await mountWithApp(AdminListPage, {
      props: { title: 'Manage Users', subtitle: 'Everyone with an account.' },
      slots: { default: '<table><caption>Users</caption></table>' },
    })

    expect(wrapper.get('h1').text()).toBe('Manage Users')
    expect(wrapper.text()).toContain('Everyone with an account.')
    expect(wrapper.find('caption').text()).toBe('Users')
  })

  it('leads back to the admin dashboard', async () => {
    const { wrapper, router } = await mountWithApp(AdminListPage, {
      props: { title: 'Manage Users' },
      path: '/admin/users',
    })

    const back = wrapper.get('a')
    expect(back.text()).toBe('Back to Dashboard')
    expect(back.attributes('href')).toBe(router.resolve({ name: 'admin-dashboard' }).href)
  })

  it('puts actions beside the title only when there are some', async () => {
    const withActions = await mountWithApp(AdminListPage, {
      props: { title: 'Decks' },
      slots: { actions: '<button type="button">Add Deck</button>' },
    })
    expect(withActions.wrapper.get('.page-header__actions').text()).toBe('Add Deck')

    const without = await mountWithApp(AdminListPage, { props: { title: 'Decks' } })
    expect(without.wrapper.find('.page-header__actions').exists()).toBe(false)
  })
})
