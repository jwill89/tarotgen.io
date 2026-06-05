<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { useDecks } from '@/composables/useDecks'
import ToastContainer from '@/components/ToastContainer.vue'
import StarField from '@/components/StarField.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import CookieBanner from '@/components/CookieBanner.vue'

const router = useRouter()
const { currentUser, isLoggedIn, logout: userLogout } = useUser()
const { fetchDecks } = useDecks()

const burgerOpen = ref(false)
const searchReadingId = ref('')
const currentYear = computed(() => new Date().getFullYear())

fetchDecks()

function viewReading() {
    const rid = searchReadingId.value.trim()
    burgerOpen.value = false
    if (rid) {
        searchReadingId.value = ''
        router.push({ name: 'reading', params: { id: rid } })
    } else {
        router.push({ name: 'new-reading' })
    }
}

async function doUserLogout() {
    burgerOpen.value = false
    await userLogout()
    router.push({ name: 'home' })
}
</script>

<template>
    <div class="app-wrapper">
        <StarField />
        <nav class="navbar is-fixed-top" role="navigation" aria-label="main navigation">
            <div class="navbar-brand">
                <span class="navbar-item has-text-weight-bold is-size-5 is-hidden-touch">🔮 TarotGen.io</span>
                <a
                    class="navbar-burger"
                    role="button"
                    aria-label="menu"
                    :aria-expanded="burgerOpen ? 'true' : 'false'"
                    :class="{ 'is-active': burgerOpen }"
                    @click="burgerOpen = !burgerOpen"
                >
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                </a>
            </div>
            <div class="navbar-menu" :class="{ 'is-active': burgerOpen }">
                <div class="navbar-start">
                    <router-link
                        class="navbar-item"
                        :to="{ name: 'home' }"
                        @click="burgerOpen = false"
                    >
                        <span class="icon"><i class="fa-solid fa-house"></i></span>
                        <span>Home</span>
                    </router-link>
                    <router-link
                        class="navbar-item"
                        :to="{ name: 'new-reading' }"
                        @click="burgerOpen = false"
                    >
                        <span class="icon"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                        <span>New Draw</span>
                    </router-link>
                    <router-link
                        class="navbar-item"
                        :to="{ name: 'custom-reading' }"
                        @click="burgerOpen = false"
                    >
                        <span class="icon"><i class="fa-solid fa-hand-sparkles"></i></span>
                        <span>Recreate Draw</span>
                    </router-link>
                    <router-link
                        class="navbar-item"
                        :to="{ name: 'submit-spread' }"
                        @click="burgerOpen = false"
                    >
                        <span class="icon"><i class="fa-solid fa-table-cells"></i></span>
                        <span>Create a Spread</span>
                    </router-link>
                    <router-link
                        v-if="isLoggedIn"
                        class="navbar-item"
                        :to="{ name: 'submit-deck' }"
                        @click="burgerOpen = false"
                    >
                        <span class="icon"><i class="fa-solid fa-layer-group"></i></span>
                        <span>Submit a Deck</span>
                    </router-link>
                </div>
                <div class="navbar-end">
                    <div class="navbar-item">
                        <div class="field has-addons">
                            <p class="control is-expanded">
                                <input
                                    class="input"
                                    type="text"
                                    v-model="searchReadingId"
                                    placeholder="Reading Code"
                                    @keyup.enter="viewReading"
                                    aria-label="Reading Code"
                                />
                            </p>
                            <p class="control">
                                <button class="button is-link" @click="viewReading">
                                    <span class="icon is-hidden-mobile"><i class="fa-solid fa-magnifying-glass"></i></span>
                                    <span>View</span>
                                </button>
                            </p>
                        </div>
                    </div>

                    <!-- Account: log in when signed out; menu when signed in -->
                    <div class="navbar-item" v-if="!isLoggedIn">
                        <div class="buttons">
                            <router-link class="button is-primary" :to="{ name: 'login' }" @click="burgerOpen = false">
                                <span class="icon"><i class="fa-solid fa-right-to-bracket"></i></span>
                                <span>Log In</span>
                            </router-link>
                        </div>
                    </div>
                    <div class="navbar-item has-dropdown is-hoverable" v-else>
                        <a class="navbar-link">
                            <span class="icon"><i class="fa-solid fa-circle-user"></i></span>
                            <span>{{ currentUser?.display_name }}</span>
                        </a>
                        <div class="navbar-dropdown is-right">
                            <router-link class="navbar-item" :to="{ name: 'dashboard' }" @click="burgerOpen = false">
                                <span class="icon"><i class="fa-solid fa-gauge-high"></i></span>
                                <span>Dashboard</span>
                            </router-link>
                            <router-link class="navbar-item" :to="{ name: 'account-readings' }" @click="burgerOpen = false">
                                <span class="icon"><i class="fa-solid fa-scroll"></i></span>
                                <span>My Readings</span>
                            </router-link>
                            <router-link class="navbar-item" :to="{ name: 'account-spreads' }" @click="burgerOpen = false">
                                <span class="icon"><i class="fa-solid fa-table-cells"></i></span>
                                <span>My Spreads</span>
                            </router-link>
                            <router-link class="navbar-item" :to="{ name: 'account-settings' }" @click="burgerOpen = false">
                                <span class="icon"><i class="fa-solid fa-gear"></i></span>
                                <span>Account Settings</span>
                            </router-link>

                            <!-- Admin section (only for is_admin accounts) -->
                            <template v-if="currentUser?.is_admin">
                                <hr class="navbar-divider" />
                                <div class="navbar-item admin-menu-label">
                                    <span class="icon"><i class="fa-solid fa-lock"></i></span>
                                    <span>Admin</span>
                                </div>
                                <router-link class="navbar-item" :to="{ name: 'admin-dashboard' }" @click="burgerOpen = false">
                                    <span class="icon"><i class="fa-solid fa-gauge-high"></i></span>
                                    <span>Dashboard</span>
                                </router-link>
                                <router-link class="navbar-item" :to="{ name: 'admin-decks' }" @click="burgerOpen = false">
                                    <span class="icon"><i class="fa-solid fa-book"></i></span>
                                    <span>Decks</span>
                                </router-link>
                                <router-link class="navbar-item" :to="{ name: 'admin-cards' }" @click="burgerOpen = false">
                                    <span class="icon"><i class="fa-solid fa-diamond"></i></span>
                                    <span>Cards</span>
                                </router-link>
                                <router-link class="navbar-item" :to="{ name: 'admin-special-cards' }" @click="burgerOpen = false">
                                    <span class="icon"><i class="fa-solid fa-star"></i></span>
                                    <span>Special Cards</span>
                                </router-link>
                                <router-link class="navbar-item" :to="{ name: 'admin-spreads' }" @click="burgerOpen = false">
                                    <span class="icon"><i class="fa-solid fa-table-cells"></i></span>
                                    <span>Spreads</span>
                                </router-link>
                                <router-link class="navbar-item" :to="{ name: 'admin-readings' }" @click="burgerOpen = false">
                                    <span class="icon"><i class="fa-solid fa-scroll"></i></span>
                                    <span>Readings</span>
                                </router-link>
                                <router-link class="navbar-item" :to="{ name: 'admin-users' }" @click="burgerOpen = false">
                                    <span class="icon"><i class="fa-solid fa-users"></i></span>
                                    <span>Users</span>
                                </router-link>
                                <router-link class="navbar-item" :to="{ name: 'admin-contacts' }" @click="burgerOpen = false">
                                    <span class="icon"><i class="fa-solid fa-envelope"></i></span>
                                    <span>Contacts</span>
                                </router-link>
                                <router-link class="navbar-item" :to="{ name: 'admin-changelog' }" @click="burgerOpen = false">
                                    <span class="icon"><i class="fa-solid fa-newspaper"></i></span>
                                    <span>Changelog</span>
                                </router-link>
                            </template>

                            <hr class="navbar-divider" />
                            <a class="navbar-item" @click="doUserLogout">
                                <span class="icon"><i class="fa-solid fa-right-from-bracket"></i></span>
                                <span>Log Out</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <router-view />

        <ConfirmDialog />
        <ToastContainer />
        <CookieBanner />

        <footer class="footer mt-auto">
            <div class="content has-text-centered">
                <p>
                    Copyright &copy; {{ currentYear }}.
                    Coded by <a href="https://www.mathdad.me"><strong>MathDad</strong></a>.<br />
                    This site is a tool for readers and those learning, it is not mean to replace
                    the need a reader. Please use responsibly!<br />
                    <router-link :to="{ name: 'privacy-policy' }">Privacy Policy</router-link> |
                    <router-link :to="{ name: 'terms-of-service' }">Terms of Service</router-link> |
                    <router-link :to="{ name: 'changelog' }"> Changelog</router-link> |
                    <router-link :to="{ name: 'contact' }">Contact Us</router-link>
                </p>
            </div>
        </footer>
    </div>
</template>
