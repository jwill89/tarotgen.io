<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { usePasskeys } from '@/composables/usePasskeys'
import { useToasts } from '@/composables/useToasts'

const router = useRouter()
const route = useRoute()
const { login } = useUser()
const { loginWithPasskey, isSupported: passkeySupported } = usePasskeys()
const toasts = useToasts()

const email = ref('')
const password = ref('')
const rememberMe = ref(false)
const error = ref('')
const loading = ref(false)
const passkeyLoading = ref(false)

onMounted(() => {
    // Handle OAuth redirect messages.
    const oauthError = route.query.oauth_error
    const oauthSuccess = route.query.oauth_success
    if (typeof oauthError === 'string' && oauthError) {
        error.value = oauthError
        router.replace({ ...route, query: {} })
    }
    if (typeof oauthSuccess === 'string' && oauthSuccess) {
        toasts.success(oauthSuccess)
        router.replace({ ...route, query: {} })
    }
})

async function submit() {
    error.value = ''
    loading.value = true
    try {
        const res = await login(email.value, password.value, rememberMe.value)
        if (res.ok) {
            toasts.success('Welcome back!')
            const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
            router.push(redirect ?? { name: 'home' })
        } else {
            error.value = res.error ?? 'Login failed.'
        }
    } finally {
        loading.value = false
    }
}

function loginWithGoogle() {
    window.location.href = '/api/auth/google?intent=login'
}

async function handlePasskeyLogin() {
    error.value = ''
    passkeyLoading.value = true
    try {
        const res = await loginWithPasskey(email.value || undefined)
        if (res.ok) {
            toasts.success('Welcome back!')
            const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
            router.push(redirect ?? { name: 'home' })
        } else {
            error.value = res.error ?? 'Passkey login failed.'
        }
    } finally {
        passkeyLoading.value = false
    }
}
</script>

<template>
    <section class="section">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-4-desktop is-6-tablet">
                    <h1 class="title is-3 has-text-centered">Log In</h1>
                    <div class="box">
                        <form @submit.prevent="submit">
                            <div class="field">
                                <label class="label" for="login-email">Email</label>
                                <div class="control has-icons-left">
                                    <input
                                        class="input"
                                        type="email"
                                        id="login-email"
                                        v-model="email"
                                        autocomplete="email"
                                        placeholder="you@example.com"
                                        required
                                        autofocus
                                    />
                                    <span class="icon is-small is-left"><i class="fa-solid fa-envelope"></i></span>
                                </div>
                            </div>

                            <div class="field">
                                <label class="label" for="login-password">Password</label>
                                <div class="control has-icons-left">
                                    <input
                                        class="input"
                                        type="password"
                                        id="login-password"
                                        v-model="password"
                                        autocomplete="current-password"
                                        required
                                    />
                                    <span class="icon is-small is-left"><i class="fa-solid fa-lock"></i></span>
                                </div>
                            </div>

                            <div class="field">
                                <label class="toggle-switch">
                                    <input type="checkbox" v-model="rememberMe" />
                                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                                    <span>Remember me</span>
                                </label>
                            </div>

                            <div class="notification is-danger is-light" v-if="error">{{ error }}</div>

                            <div class="field">
                                <button
                                    class="button is-primary is-fullwidth"
                                    type="submit"
                                    :class="{ 'is-loading': loading }"
                                    :disabled="loading"
                                >
                                    <span class="icon"><i class="fa-solid fa-right-to-bracket"></i></span>
                                    <span>Log In</span>
                                </button>
                            </div>

                            <div class="field">
                                <div class="is-divider">OR</div>
                            </div>

                            <div class="field">
                                <button
                                    class="button is-fullwidth"
                                    type="button"
                                    @click="loginWithGoogle"
                                >
                                    <span class="icon"><i class="fa-brands fa-google"></i></span>
                                    <span>Sign in with Google</span>
                                </button>
                            </div>

                            <div class="field" v-if="passkeySupported()">
                                <button
                                    class="button is-fullwidth"
                                    type="button"
                                    :class="{ 'is-loading': passkeyLoading }"
                                    :disabled="passkeyLoading"
                                    @click="handlePasskeyLogin"
                                >
                                    <span class="icon"><i class="fa-solid fa-key"></i></span>
                                    <span>Sign in with Passkey</span>
                                </button>
                            </div>

                            <p class="has-text-centered is-size-7">
                                <router-link :to="{ name: 'forgot-password' }">Forgot your password?</router-link>
                            </p>
                            <div class="has-text-centered mt-4">
                                <p class="mb-2">Don't have an account?</p>
                                <router-link class="button is-link is-outlined" :to="{ name: 'register' }">
                                    <span class="icon"><i class="fa-solid fa-user-plus"></i></span>
                                    <span>Create an Account</span>
                                </router-link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
