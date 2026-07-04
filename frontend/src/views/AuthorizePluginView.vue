<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { usePluginLink } from '@/composables/usePluginLink'

// The plugin opens this page with the PKCE + loopback query params.
const route = useRoute()
const { currentUser, isLoggedIn, fetchMe } = useUser()
const { authorize } = usePluginLink()

const q = route.query
const codeChallenge = String(q.code_challenge ?? '')
const method = String(q.code_challenge_method ?? 'S256')
const redirectUri = String(q.redirect_uri ?? '')
const state = String(q.state ?? '')

// Only a loopback redirect is ever legitimate here (the code goes to the plugin's
// local listener). The server enforces this too; the client check is UX.
const isLoopback = computed(() =>
  /^http:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?(\/|$)/i.test(redirectUri),
)
const paramsValid = computed(() => codeChallenge !== '' && method === 'S256' && isLoopback.value)

const checkingSession = ref(true)
const submitting = ref(false)
const error = ref<string | null>(null)
const done = ref(false)

onMounted(async () => {
  // Revalidate against the server directly so we reflect the real login state
  // (an already-signed-in user should go straight to the consent, never /login).
  await fetchMe()
  checkingSession.value = false
})

// A login link that returns to THIS page (with its query) once signed in.
const loginTo = computed(() => ({ name: 'login', query: { redirect: route.fullPath } }))

async function approve(): Promise<void> {
  submitting.value = true
  error.value = null

  const res = await authorize({
    code_challenge: codeChallenge,
    code_challenge_method: method,
    redirect_uri: redirectUri,
    state,
  })

  submitting.value = false

  if (res.ok && res.redirectUri) {
    done.value = true
    // Hand the authorization code back to the plugin's loopback listener.
    window.location.href = res.redirectUri
  } else {
    error.value = res.error ?? 'Authorization failed.'
  }
}
</script>

<template>
  <section class="section">
    <div class="container" style="max-width: 40rem">
      <h1 class="title">Link a Plugin</h1>

      <div v-if="!paramsValid" class="notification is-warning">
        This authorization link is missing or has invalid parameters. Please start the link from the
        plugin's settings in-game.
      </div>

      <div v-else-if="checkingSession" class="has-text-grey">Checking your session…</div>

      <div v-else-if="!isLoggedIn" class="box">
        <p class="mb-4">Sign in to your TarotGen account to link the plugin.</p>
        <router-link class="button is-primary" :to="loginTo">Log in to continue</router-link>
      </div>

      <div v-else-if="done" class="notification is-success">
        <p><strong>Plugin linked.</strong> You can close this tab and return to the game.</p>
      </div>

      <div v-else class="box">
        <p class="mb-4">
          The <strong>TarotGen FFXIV plugin</strong> wants to link to your account (<strong>{{
            currentUser?.display_name
          }}</strong
          >).
        </p>

        <p class="mb-2">Once linked, the plugin will be able to:</p>
        <ul class="mb-4 ml-5" style="list-style: disc">
          <li>See the readings and favorites saved to your account</li>
          <li>Track readings you generate to your account</li>
          <li>Lock a reading you own</li>
        </ul>

        <p class="mb-4 has-text-grey">
          It <strong>cannot</strong> change your password, delete your account, or manage other
          links. You can revoke this link any time from Account Settings.
        </p>

        <p v-if="error" class="help is-danger mb-3">{{ error }}</p>

        <div class="buttons">
          <button
            class="button is-primary"
            :class="{ 'is-loading': submitting }"
            :disabled="submitting"
            @click="approve"
          >
            Authorize
          </button>
          <router-link class="button" :to="{ name: 'home' }">Cancel</router-link>
        </div>
      </div>
    </div>
  </section>
</template>
