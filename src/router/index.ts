import { createRouter, createWebHistory } from 'vue-router'
import { useUser } from '@/composables/useUser'

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
            beforeEnter: (to) => {
                const rid = to.query.rid as string | undefined
                if (rid) return { name: 'reading', params: { id: rid } }
            },
        },
        { path: '/new', name: 'new-reading', component: () => import('@/views/NewReadingView.vue'), meta: { title: 'New Reading' } },
        { path: '/custom', name: 'custom-reading', component: () => import('@/views/CustomReadingView.vue'), meta: { title: 'Custom Reading' } },
        { path: '/reading/:id/placement', name: 'free-draw-placement', component: () => import('@/views/FreeDrawPlacementView.vue'), meta: { title: 'Arrange Draw' } },
        { path: '/reading/:id', name: 'reading', component: () => import('@/views/ReadingView.vue'), meta: { title: 'Reading' } },
        { path: '/create-spread', name: 'submit-spread', component: () => import('@/views/SubmitSpreadView.vue'), meta: { title: 'Create Spread' } },
        { path: '/submit-spread', redirect: { name: 'submit-spread' } },
        { path: '/submit-deck', name: 'submit-deck', component: () => import('@/views/SubmitDeckView.vue'), meta: { title: 'Submit a Deck' } },
        { path: '/submit-deck-system', name: 'submit-deck-system', component: () => import('@/views/SubmitDeckSystemView.vue'), meta: { title: 'Submit a Deck System' } },
        { path: '/contact', name: 'contact', component: () => import('@/views/ContactView.vue'), meta: { title: 'Contact' } },
        { path: '/changelog', name: 'changelog', component: () => import('@/views/ChangelogView.vue'), meta: { title: 'Changelog' } },
        { path: '/privacy', name: 'privacy-policy', component: () => import('@/views/PrivacyPolicyView.vue'), meta: { title: 'Privacy Policy' } },
        { path: '/terms', name: 'terms-of-service', component: () => import('@/views/TermsOfServiceView.vue'), meta: { title: 'Terms of Service' } },
        { path: '/register', name: 'register', component: () => import('@/views/RegisterView.vue'), meta: { title: 'Register', userGuest: true } },
        { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { title: 'Login', userGuest: true } },
        { path: '/activate', name: 'activate', component: () => import('@/views/ActivateView.vue'), meta: { title: 'Activate Account' } },
        { path: '/forgot-password', name: 'forgot-password', component: () => import('@/views/ForgotPasswordView.vue'), meta: { title: 'Forgot Password' } },
        { path: '/reset-password', name: 'reset-password', component: () => import('@/views/ResetPasswordView.vue'), meta: { title: 'Reset Password' } },
        { path: '/account', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: 'Dashboard', userOnly: true } },
        { path: '/account/readings', name: 'account-readings', component: () => import('@/views/AccountReadingsView.vue'), meta: { title: 'My Readings', userOnly: true } },
        { path: '/account/settings', name: 'account-settings', component: () => import('@/views/AccountSettingsView.vue'), meta: { title: 'Account Settings', userOnly: true } },
        { path: '/account/spreads', name: 'account-spreads', component: () => import('@/views/AccountSpreadsView.vue'), meta: { title: 'My Spreads', userOnly: true } },
        // Legacy admin-login URL now points at the unified account login.
        { path: '/admin/login', redirect: { name: 'login' } },
        { path: '/admin', name: 'admin-dashboard', component: () => import('@/views/admin/DashboardView.vue'), meta: { title: 'Admin Dashboard', admin: true } },
        { path: '/admin/decks', name: 'admin-decks', component: () => import('@/views/admin/DecksView.vue'), meta: { title: 'Admin Decks', admin: true } },
        { path: '/admin/deck-systems', name: 'admin-deck-systems', component: () => import('@/views/admin/DeckSystemsView.vue'), meta: { title: 'Admin Deck Systems', admin: true } },
        { path: '/admin/special-cards', name: 'admin-special-cards', component: () => import('@/views/admin/SpecialCardsView.vue'), meta: { title: 'Admin Special Cards', admin: true } },
        { path: '/admin/spreads', name: 'admin-spreads', component: () => import('@/views/admin/SpreadsView.vue'), meta: { title: 'Admin Spreads', admin: true } },
        { path: '/admin/readings', name: 'admin-readings', component: () => import('@/views/admin/ReadingsView.vue'), meta: { title: 'Admin Readings', admin: true } },
        { path: '/admin/changelog', name: 'admin-changelog', component: () => import('@/views/admin/ChangelogView.vue'), meta: { title: 'Admin Changelog', admin: true } },
        { path: '/admin/users', name: 'admin-users', component: () => import('@/views/admin/UsersView.vue'), meta: { title: 'Admin Users', admin: true } },
        { path: '/admin/contacts', name: 'admin-contacts', component: () => import('@/views/admin/ContactsView.vue'), meta: { title: 'Admin Contacts', admin: true } },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
})

router.afterEach((to) => {
    const pageTitle = to.meta.title as string | undefined
    document.title = pageTitle ? `${pageTitle} | ${BASE_TITLE}` : BASE_TITLE
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
    if (to.meta.admin && !(currentUser.value?.is_admin)) {
        return isLoggedIn.value
            ? { name: 'home' }
            : { name: 'login', query: { redirect: to.fullPath } }
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
