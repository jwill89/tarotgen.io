<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { endpoints } from '@/api/endpoints'

const route = useRoute()
const router = useRouter()
const { register } = useUser()

const email = ref('')
const displayName = ref('')
const password = ref('')
const confirm = ref('')
const showPassword = ref(false)

const loading = ref(false)
const errors = ref<string[]>([])
const done = ref(false)
const successMessage = ref('')
const activationLink = ref('')

const passwordsMatch = computed(() => confirm.value === '' || password.value === confirm.value)

onMounted(() => {
  const oauthError = route.query.oauth_error
  if (typeof oauthError === 'string' && oauthError) {
    errors.value = [oauthError]
    void router.replace({ ...route, query: {} })
  }
})

async function submit() {
  errors.value = []

  if (password.value !== confirm.value) {
    errors.value = ['Passwords do not match.']
    return
  }

  loading.value = true
  try {
    const res = await register(email.value, displayName.value, password.value)
    if (res.ok) {
      done.value = true
      successMessage.value = res.message ?? 'Account created. Check your email to activate it.'
      activationLink.value = res.activationLink ?? ''
    } else {
      errors.value = res.errors ?? ['Registration failed. Please try again.']
    }
  } finally {
    loading.value = false
  }
}

function registerWithGoogle() {
  window.location.href = '/api' + endpoints.authGoogle.start + '?intent=register'
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-5-desktop is-7-tablet">
          <h1 class="title is-3 has-text-centered">Create an Account</h1>
          <p class="subtitle is-6 has-text-centered">
            Save and revisit your readings. (Two-factor and passkeys are coming later.)
          </p>

          <div v-if="!done" class="box">
            <form @submit.prevent="submit">
              <div class="field">
                <label class="label" for="reg-email">Email</label>
                <div class="control has-icons-left">
                  <input
                    id="reg-email"
                    v-model="email"
                    class="input"
                    type="email"
                    autocomplete="email"
                    placeholder="you@example.com"
                    required
                  />
                  <span class="icon is-small is-left"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['envelope']"
                  /></span>
                </div>
              </div>

              <div class="field">
                <label class="label" for="reg-name">Display Name</label>
                <div class="control has-icons-left">
                  <input
                    id="reg-name"
                    v-model="displayName"
                    class="input"
                    type="text"
                    autocomplete="nickname"
                    maxlength="30"
                    placeholder="How you'll appear on the site"
                    required
                  />
                  <span class="icon is-small is-left"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['user']"
                  /></span>
                </div>
              </div>

              <div class="field">
                <label class="label" for="reg-password">Password</label>
                <div class="control has-icons-left">
                  <input
                    id="reg-password"
                    v-model="password"
                    class="input"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="new-password"
                    placeholder="At least 12 characters"
                    required
                  />
                  <span class="icon is-small is-left"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']"
                  /></span>
                </div>
                <p class="help">
                  Use at least 12 characters. A memorable passphrase (a few random words) is both
                  strong and easy to remember.
                </p>
              </div>

              <div class="field">
                <label class="label" for="reg-confirm">Confirm Password</label>
                <div class="control has-icons-left">
                  <input
                    id="reg-confirm"
                    v-model="confirm"
                    class="input"
                    :class="{ 'is-danger': !passwordsMatch }"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="new-password"
                    required
                  />
                  <span class="icon is-small is-left"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']"
                  /></span>
                </div>
                <p v-if="!passwordsMatch" class="help is-danger">Passwords do not match.</p>
              </div>

              <div class="field">
                <label class="checkbox-inline toggle-switch">
                  <input v-model="showPassword" type="checkbox" />
                  <span class="toggle-track"><span class="toggle-thumb"></span></span>
                  <span class="toggle-state">Show password</span>
                </label>
              </div>

              <div v-if="errors.length" class="notification is-danger is-light">
                <ul class="reg-errors">
                  <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
                </ul>
              </div>

              <div class="field mt-4">
                <button
                  class="button is-success is-fullwidth"
                  type="submit"
                  :class="{ 'is-loading': loading }"
                  :disabled="loading"
                >
                  <span class="icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['user-plus']"
                  /></span>
                  <span>Create Account</span>
                </button>
              </div>

              <div class="field">
                <div class="is-divider">OR</div>
              </div>

              <div class="field">
                <button class="button is-fullwidth" type="button" @click="registerWithGoogle">
                  <span class="icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fab['google']"
                  /></span>
                  <span>Sign up with Google</span>
                </button>
              </div>

              <p class="has-text-centered is-size-7">
                Already have an account?
                <router-link :to="{ name: 'login' }">Log in</router-link>.
              </p>
            </form>
          </div>

          <div v-else class="box">
            <div class="notification is-success is-light">
              <span class="icon"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['envelope-circle-check']"
              /></span>
              {{ successMessage }}
            </div>

            <div v-if="activationLink" class="notification is-warning is-light">
              <p class="mb-2">
                <strong>Dev shortcut:</strong> email isn't configured here, so activate directly:
              </p>
              <a class="button is-small is-link" :href="activationLink">Activate my account</a>
            </div>

            <div class="buttons is-centered mt-4">
              <router-link class="button is-primary" :to="{ name: 'login' }">
                <span class="icon"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['right-to-bracket']"
                /></span>
                <span>Go to Login</span>
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.reg-errors {
  list-style: disc;
  margin-left: 1.1rem;
}
</style>
