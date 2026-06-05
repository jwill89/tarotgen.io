<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useUser } from '@/composables/useUser'

const route = useRoute()
const { resetPassword } = useUser()

const token = typeof route.query.token === 'string' ? route.query.token : ''

const password = ref('')
const confirm = ref('')
const showPassword = ref(false)
const loading = ref(false)
const error = ref('')
const done = ref(false)

const passwordsMatch = computed(() => confirm.value === '' || password.value === confirm.value)
const missingToken = computed(() => token === '')

async function submit() {
    error.value = ''

    if (password.value !== confirm.value) {
        error.value = 'Passwords do not match.'
        return
    }

    loading.value = true
    try {
        const res = await resetPassword(token, password.value)
        if (res.ok) {
            done.value = true
        } else {
            error.value = res.error ?? 'Reset failed.'
        }
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <section class="section">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-4-desktop is-6-tablet">
                    <h1 class="title is-3 has-text-centered">Set a New Password</h1>

                    <div class="box" v-if="missingToken">
                        <div class="notification is-danger is-light">
                            This reset link is missing its token. Please request a new link.
                        </div>
                        <div class="buttons is-centered">
                            <router-link class="button is-primary" :to="{ name: 'forgot-password' }">Request a new link</router-link>
                        </div>
                    </div>

                    <div class="box" v-else-if="!done">
                        <form @submit.prevent="submit">
                            <div class="field">
                                <label class="label" for="rp-password">New Password</label>
                                <div class="control has-icons-left">
                                    <input
                                        class="input"
                                        :type="showPassword ? 'text' : 'password'"
                                        id="rp-password"
                                        v-model="password"
                                        autocomplete="new-password"
                                        placeholder="At least 12 characters"
                                        required
                                        autofocus
                                    />
                                    <span class="icon is-small is-left"><i class="fa-solid fa-lock"></i></span>
                                </div>
                                <p class="help">Use at least 12 characters. A memorable passphrase is strong and easy to recall.</p>
                            </div>

                            <div class="field">
                                <label class="label" for="rp-confirm">Confirm New Password</label>
                                <div class="control has-icons-left">
                                    <input
                                        class="input"
                                        :class="{ 'is-danger': !passwordsMatch }"
                                        :type="showPassword ? 'text' : 'password'"
                                        id="rp-confirm"
                                        v-model="confirm"
                                        autocomplete="new-password"
                                        required
                                    />
                                    <span class="icon is-small is-left"><i class="fa-solid fa-lock"></i></span>
                                </div>
                                <p class="help is-danger" v-if="!passwordsMatch">Passwords do not match.</p>
                            </div>

                            <div class="field">
                                <label class="checkbox-inline toggle-switch">
                                    <input type="checkbox" v-model="showPassword" />
                                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                                    <span class="toggle-state">Show password</span>
                                </label>
                            </div>

                            <div class="notification is-danger is-light" v-if="error">{{ error }}</div>

                            <div class="field mt-4">
                                <button
                                    class="button is-success is-fullwidth"
                                    type="submit"
                                    :class="{ 'is-loading': loading }"
                                    :disabled="loading"
                                >
                                    <span class="icon"><i class="fa-solid fa-key"></i></span>
                                    <span>Update Password</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="box" v-else>
                        <div class="notification is-success is-light">
                            <span class="icon"><i class="fa-solid fa-circle-check"></i></span>
                            Your password has been updated. You can now log in.
                        </div>
                        <div class="buttons is-centered mt-4">
                            <router-link class="button is-primary" :to="{ name: 'login' }">
                                <span class="icon"><i class="fa-solid fa-right-to-bracket"></i></span>
                                <span>Log In</span>
                            </router-link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
