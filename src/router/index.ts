import { createRouter, createWebHistory } from 'vue-router'
import { useUser } from '@/composables/useUser'

import HomeView from '@/views/HomeView.vue'
import NewReadingView from '@/views/NewReadingView.vue'
import ReadingView from '@/views/ReadingView.vue'
import CustomReadingView from '@/views/CustomReadingView.vue'
import FreeDrawPlacementView from '@/views/FreeDrawPlacementView.vue'
import SubmitSpreadView from '@/views/SubmitSpreadView.vue'
import SubmitDeckView from '@/views/SubmitDeckView.vue'
import SubmitDeckSystemView from '@/views/SubmitDeckSystemView.vue'
import ChangelogView from '@/views/ChangelogView.vue'
import RegisterView from '@/views/RegisterView.vue'
import LoginView from '@/views/LoginView.vue'
import ActivateView from '@/views/ActivateView.vue'
import ForgotPasswordView from '@/views/ForgotPasswordView.vue'
import ResetPasswordView from '@/views/ResetPasswordView.vue'
import DashboardView from '@/views/DashboardView.vue'
import AccountReadingsView from '@/views/AccountReadingsView.vue'
import AccountSettingsView from '@/views/AccountSettingsView.vue'
import AccountSpreadsView from '@/views/AccountSpreadsView.vue'
import AdminDashboardView from '@/views/admin/DashboardView.vue'
import AdminDecksView from '@/views/admin/DecksView.vue'
import AdminDeckSystemsView from '@/views/admin/DeckSystemsView.vue'
import AdminSpecialCardsView from '@/views/admin/SpecialCardsView.vue'
import AdminSpreadsView from '@/views/admin/SpreadsView.vue'
import AdminReadingsView from '@/views/admin/ReadingsView.vue'
import AdminChangelogView from '@/views/admin/ChangelogView.vue'
import AdminUsersView from '@/views/admin/UsersView.vue'
import AdminContactsView from '@/views/admin/ContactsView.vue'
import ContactView from '@/views/ContactView.vue'
import PrivacyPolicyView from '@/views/PrivacyPolicyView.vue'
import TermsOfServiceView from '@/views/TermsOfServiceView.vue'

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
            component: HomeView,
            beforeEnter: (to) => {
                const rid = to.query.rid as string | undefined
                if (rid) return { name: 'reading', params: { id: rid } }
            },
        },
        { path: '/new', name: 'new-reading', component: NewReadingView, meta: { title: 'New Reading' } },
        { path: '/custom', name: 'custom-reading', component: CustomReadingView, meta: { title: 'Custom Reading' } },
        { path: '/reading/:id/placement', name: 'free-draw-placement', component: FreeDrawPlacementView, meta: { title: 'Arrange Draw' } },
        { path: '/reading/:id', name: 'reading', component: ReadingView, meta: { title: 'Reading' } },
        { path: '/create-spread', name: 'submit-spread', component: SubmitSpreadView, meta: { title: 'Create Spread' } },
        { path: '/submit-spread', redirect: { name: 'submit-spread' } },
        { path: '/submit-deck', name: 'submit-deck', component: SubmitDeckView, meta: { title: 'Submit a Deck' } },
        { path: '/submit-deck-system', name: 'submit-deck-system', component: SubmitDeckSystemView, meta: { title: 'Submit a Deck System' } },
        { path: '/contact', name: 'contact', component: ContactView, meta: { title: 'Contact' } },
        { path: '/changelog', name: 'changelog', component: ChangelogView, meta: { title: 'Changelog' } },
        { path: '/privacy', name: 'privacy-policy', component: PrivacyPolicyView, meta: { title: 'Privacy Policy' } },
        { path: '/terms', name: 'terms-of-service', component: TermsOfServiceView, meta: { title: 'Terms of Service' } },
        { path: '/register', name: 'register', component: RegisterView, meta: { title: 'Register', userGuest: true } },
        { path: '/login', name: 'login', component: LoginView, meta: { title: 'Login', userGuest: true } },
        { path: '/activate', name: 'activate', component: ActivateView, meta: { title: 'Activate Account' } },
        { path: '/forgot-password', name: 'forgot-password', component: ForgotPasswordView, meta: { title: 'Forgot Password' } },
        { path: '/reset-password', name: 'reset-password', component: ResetPasswordView, meta: { title: 'Reset Password' } },
        { path: '/account', name: 'dashboard', component: DashboardView, meta: { title: 'Dashboard', userOnly: true } },
        { path: '/account/readings', name: 'account-readings', component: AccountReadingsView, meta: { title: 'My Readings', userOnly: true } },
        { path: '/account/settings', name: 'account-settings', component: AccountSettingsView, meta: { title: 'Account Settings', userOnly: true } },
        { path: '/account/spreads', name: 'account-spreads', component: AccountSpreadsView, meta: { title: 'My Spreads', userOnly: true } },
        // Legacy admin-login URL now points at the unified account login.
        { path: '/admin/login', redirect: { name: 'login' } },
        { path: '/admin', name: 'admin-dashboard', component: AdminDashboardView, meta: { title: 'Admin Dashboard', admin: true } },
        { path: '/admin/decks', name: 'admin-decks', component: AdminDecksView, meta: { title: 'Admin Decks', admin: true } },
        { path: '/admin/deck-systems', name: 'admin-deck-systems', component: AdminDeckSystemsView, meta: { title: 'Admin Deck Systems', admin: true } },
        { path: '/admin/special-cards', name: 'admin-special-cards', component: AdminSpecialCardsView, meta: { title: 'Admin Special Cards', admin: true } },
        { path: '/admin/spreads', name: 'admin-spreads', component: AdminSpreadsView, meta: { title: 'Admin Spreads', admin: true } },
        { path: '/admin/readings', name: 'admin-readings', component: AdminReadingsView, meta: { title: 'Admin Readings', admin: true } },
        { path: '/admin/changelog', name: 'admin-changelog', component: AdminChangelogView, meta: { title: 'Admin Changelog', admin: true } },
        { path: '/admin/users', name: 'admin-users', component: AdminUsersView, meta: { title: 'Admin Users', admin: true } },
        { path: '/admin/contacts', name: 'admin-contacts', component: AdminContactsView, meta: { title: 'Admin Contacts', admin: true } },
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
