import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import DashboardView from '@/views/DashboardView.vue'
import { useUser } from '@/composables/useUser'
import { mountWithApp, cleanup, stubApi, signIn, testUser } from './helpers/app'

const text = (el: Element) => el.textContent.replace(/\s+/g, ' ').trim()
const linkTo = (title: string) =>
  [...document.body.querySelectorAll('a')]
    .find((a) => a.querySelector('.title')?.textContent === title)
    ?.getAttribute('href')
const logoutButton = () =>
  [...document.body.querySelectorAll('button')].find((b) => text(b).startsWith('Logout'))

describe('DashboardView', () => {
  beforeEach(() => signIn(testUser()))
  afterEach(cleanup)

  it('leads to each part of the account', async () => {
    stubApi({})
    await mountWithApp(DashboardView, { path: '/account' })

    expect(linkTo('My Readings')).toBe('/account/readings')
    expect(linkTo('My Spreads')).toBe('/account/spreads')
    expect(linkTo('Account Settings')).toBe('/account/settings')
  })

  it('signs out and goes home', async () => {
    const api = stubApi({ 'POST /auth/logout': { status: 204 } })
    const { router } = await mountWithApp(DashboardView, { path: '/account' })

    logoutButton()?.click()
    await flushPromises()

    expect(api).toHaveBeenCalledTimes(1)
    expect(useUser().isLoggedIn.value).toBe(false)
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'))
  })

  it('signs out on this device even if the server cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
    await mountWithApp(DashboardView, { path: '/account' })

    logoutButton()?.click()
    await flushPromises()

    expect(useUser().isLoggedIn.value).toBe(false)
  })
})
