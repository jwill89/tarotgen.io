<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref } from 'vue'
import { useUser } from '@/composables/useUser'

const { requestPasswordReset } = useUser()

const email = ref('')
const loading = ref(false)
const done = ref(false)
const message = ref('')
const resetLink = ref('')

async function submit() {
    loading.value = true
    try {
        const res = await requestPasswordReset(email.value)
        done.value = true
        message.value = res.message ?? 'If an account exists for that email, a reset link has been sent.'
        resetLink.value = res.resetLink ?? ''
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
                    <h1 class="title is-3 has-text-centered">Reset Password</h1>

                    <div class="box" v-if="!done">
                        <p class="mb-4">Enter your email and we'll send you a link to reset your password.</p>
                        <form @submit.prevent="submit">
                            <div class="field">
                                <label class="label" for="fp-email">Email</label>
                                <div class="control has-icons-left">
                                    <input
                                        class="input"
                                        type="email"
                                        id="fp-email"
                                        v-model="email"
                                        autocomplete="email"
                                        placeholder="you@example.com"
                                        required
                                        autofocus
                                    />
                                    <span class="icon is-small is-left"><FontAwesomeIcon :icon="byPrefixAndName.fas['envelope']" /></span>
                                </div>
                            </div>

                            <div class="field">
                                <button
                                    class="button is-primary is-fullwidth"
                                    type="submit"
                                    :class="{ 'is-loading': loading }"
                                    :disabled="loading"
                                >
                                    <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['paper-plane']" /></span>
                                    <span>Send Reset Link</span>
                                </button>
                            </div>

                            <p class="has-text-centered is-size-7">
                                Remembered it? <router-link :to="{ name: 'login' }">Back to login</router-link>.
                            </p>
                        </form>
                    </div>

                    <div class="box" v-else>
                        <div class="notification is-success is-light">
                            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['envelope-circle-check']" /></span>
                            {{ message }}
                        </div>

                        <div class="notification is-warning is-light" v-if="resetLink">
                            <p class="mb-2"><strong>Dev shortcut:</strong> email isn't configured here, so reset directly:</p>
                            <a class="button is-small is-link" :href="resetLink">Reset my password</a>
                        </div>

                        <div class="buttons is-centered mt-4">
                            <router-link class="button" :to="{ name: 'login' }">Back to Login</router-link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
