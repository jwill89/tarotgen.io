import { createRouter, createWebHistory } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { applyRouteSeo } from '@/utils/seo'

// Route components are loaded lazily (() => import(...)) so each view — and its
// heavier dependencies (e.g. the TipTap markdown editor, admin tables) — is
// split into its own chunk and only fetched when that route is visited. This
// keeps the initial bundle to the app shell + shared vendor code.

const BASE_TITLE = 'TarotGen.io Tarot Generator'

/**
 * Minimum interval (ms) between auth revalidation checks to avoid hammering
 * the server on rapid navigation.
 */
const AUTH_CHECK_INTERVAL = 60_000 // 1 minute
let lastAuthCheck = 0

/** Call after an external auth check (e.g. app boot fetchMe) to avoid duplicate checks. */
export function markAuthChecked(): void {
  lastAuthCheck = Date.now()
}

const router = createRouter({
  history: createWebHistory(),
  scrollBehavior() {
    return { top: 0 }
  },
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('@/views/HomeView.vue'),
      meta: {
        description:
          'Draw your own tarot spreads online. Pick from a variety of decks, choose a classic spread or freely draw cards, and share your reading with a link.',
      },
      beforeEnter: (to) => {
        const rid = to.query.rid as string | undefined
        if (rid) return { name: 'reading', params: { id: rid } }
      },
    },
    {
      path: '/new',
      name: 'new-reading',
      component: () => import('@/views/NewReadingView.vue'),
      meta: {
        title: 'New Reading',
        description:
          'Start a new tarot reading: choose a deck and a spread, then draw your cards instantly.',
      },
    },
    {
      path: '/custom',
      name: 'custom-reading',
      component: () => import('@/views/CustomReadingView.vue'),
      meta: {
        title: 'Custom Reading',
        description:
          'Recreate a real tarot spread by placing specific cards in the positions you choose.',
      },
    },
    {
      path: '/reading/:id/placement',
      name: 'free-draw-placement',
      component: () => import('@/views/FreeDrawPlacementView.vue'),
      meta: { title: 'Arrange Draw', noindex: true },
    },
    {
      path: '/reading/:id',
      name: 'reading',
      component: () => import('@/views/ReadingView.vue'),
      meta: { title: 'Reading', noindex: true },
    },
    {
      path: '/create-spread',
      name: 'submit-spread',
      component: () => import('@/views/SubmitSpreadView.vue'),
      meta: {
        title: 'Create Spread',
        description: 'Design a custom tarot spread to use yourself or share with other readers.',
      },
    },
    { path: '/submit-spread', redirect: { name: 'submit-spread' } },
    {
      path: '/submit-deck',
      name: 'submit-deck',
      component: () => import('@/views/SubmitDeckView.vue'),
      meta: {
        title: 'Submit a Deck',
        description: 'Suggest a tarot deck to be added to TarotGen.io.',
      },
    },
    {
      path: '/submit-deck-system',
      name: 'submit-deck-system',
      component: () => import('@/views/SubmitDeckSystemView.vue'),
      meta: {
        title: 'Submit a Deck System',
        description: 'Propose a new tarot deck system for TarotGen.io.',
      },
    },
    {
      path: '/contact',
      name: 'contact',
      component: () => import('@/views/ContactView.vue'),
      meta: { title: 'Contact', description: 'Get in touch with the team behind TarotGen.io.' },
    },
    {
      path: '/changelog',
      name: 'changelog',
      component: () => import('@/views/ChangelogView.vue'),
      meta: {
        title: 'Changelog',
        description: 'See the latest updates, features, and fixes for TarotGen.io.',
      },
    },
    {
      path: '/privacy',
      name: 'privacy-policy',
      component: () => import('@/views/PrivacyPolicyView.vue'),
      meta: {
        title: 'Privacy Policy',
        description: 'How TarotGen.io collects, uses, and protects your data.',
      },
    },
    {
      path: '/terms',
      name: 'terms-of-service',
      component: () => import('@/views/TermsOfServiceView.vue'),
      meta: {
        title: 'Terms of Service',
        description: 'The terms governing your use of TarotGen.io.',
      },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { title: 'Register', userGuest: true, noindex: true },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { title: 'Login', userGuest: true, noindex: true },
    },
    {
      path: '/activate',
      name: 'activate',
      component: () => import('@/views/ActivateView.vue'),
      meta: { title: 'Activate Account', noindex: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
      meta: { title: 'Forgot Password', noindex: true },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
      meta: { title: 'Reset Password', noindex: true },
    },
    {
      path: '/account',
      name: 'dashboard',
      component: () => import('@/views/DashboardView.vue'),
      meta: { title: 'Dashboard', userOnly: true, noindex: true },
    },
    {
      path: '/account/readings',
      name: 'account-readings',
      component: () => import('@/views/AccountReadingsView.vue'),
      meta: { title: 'My Readings', userOnly: true, noindex: true },
    },
    {
      path: '/account/settings',
      name: 'account-settings',
      component: () => import('@/views/AccountSettingsView.vue'),
      meta: { title: 'Account Settings', userOnly: true, noindex: true },
    },
    {
      path: '/account/spreads',
      name: 'account-spreads',
      component: () => import('@/views/AccountSpreadsView.vue'),
      meta: { title: 'My Spreads', userOnly: true, noindex: true },
    },
    // Legacy admin-login URL now points at the unified account login.
    { path: '/admin/login', redirect: { name: 'login' } },
    {
      path: '/admin',
      name: 'admin-dashboard',
      component: () => import('@/views/admin/DashboardView.vue'),
      meta: { title: 'Admin Dashboard', admin: true, noindex: true },
    },
    {
      path: '/admin/decks',
      name: 'admin-decks',
      component: () => import('@/views/admin/DecksView.vue'),
      meta: { title: 'Admin Decks', admin: true, noindex: true },
    },
    {
      path: '/admin/deck-systems',
      name: 'admin-deck-systems',
      component: () => import('@/views/admin/DeckSystemsView.vue'),
      meta: { title: 'Admin Deck Systems', admin: true, noindex: true },
    },
    {
      path: '/admin/special-cards',
      name: 'admin-special-cards',
      component: () => import('@/views/admin/SpecialCardsView.vue'),
      meta: { title: 'Admin Special Cards', admin: true, noindex: true },
    },
    {
      path: '/admin/spreads',
      name: 'admin-spreads',
      component: () => import('@/views/admin/SpreadsView.vue'),
      meta: { title: 'Admin Spreads', admin: true, noindex: true },
    },
    {
      path: '/admin/readings',
      name: 'admin-readings',
      component: () => import('@/views/admin/ReadingsView.vue'),
      meta: { title: 'Admin Readings', admin: true, noindex: true },
    },
    {
      path: '/admin/changelog',
      name: 'admin-changelog',
      component: () => import('@/views/admin/ChangelogView.vue'),
      meta: { title: 'Admin Changelog', admin: true, noindex: true },
    },
    {
      path: '/admin/users',
      name: 'admin-users',
      component: () => import('@/views/admin/UsersView.vue'),
      meta: { title: 'Admin Users', admin: true, noindex: true },
    },
    {
      path: '/admin/contacts',
      name: 'admin-contacts',
      component: () => import('@/views/admin/ContactsView.vue'),
      meta: { title: 'Admin Contacts', admin: true, noindex: true },
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.afterEach((to) => {
  const pageTitle = to.meta.title as string | undefined
  document.title = pageTitle ? `${pageTitle} | ${BASE_TITLE}` : BASE_TITLE

  // Keep description, canonical and robots tags in sync with the route, so
  // crawlers (and Google's rendered index) don't see the homepage meta on
  // every page. Reading pages are also server-noindexed via og.php.
  applyRouteSeo(
    { description: to.meta.description as string | undefined, noindex: to.meta.noindex === true },
    to.path,
  )
})

router.beforeEach(async (to) => {
  const { currentUser, isLoggedIn, fetchMe } = useUser()

  // Revalidate the session against the server when the user appears logged in
  // and we haven't checked recently. This prevents stale UI after session expiry.
  if (isLoggedIn.value && Date.now() - lastAuthCheck > AUTH_CHECK_INTERVAL) {
    await fetchMe()
    lastAuthCheck = Date.now()
  }

  // Admin pages require an authenticated account with the is_admin flag.
  if (to.meta.admin && !currentUser.value?.is_admin) {
    return isLoggedIn.value ? { name: 'home' } : { name: 'login', query: { redirect: to.fullPath } }
  }

  // Logged-in users shouldn't see the register/login screens.
  if (to.meta.userGuest && isLoggedIn.value) {
    return { name: 'home' }
  }

  // Account pages require a logged-in user.
  if (to.meta.userOnly && !isLoggedIn.value) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
})

export default router
