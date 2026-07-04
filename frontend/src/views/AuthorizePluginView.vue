<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { usePluginLink } from '@/composables/usePluginLink'

// The plugin opens this page with the PKCE + loopback query params.
const route = useRoute()
const { currentUser, isLoggedIn, fetchMe } = useUser()
const { authorize, connectGuest } = usePluginLink()

const q = route.query
const codeChallenge = String(q.code_challenge ?? '')
const method = String(q.code_challenge_method ?? 'S256')
const redirectUri = String(q.redirect_uri ?? '')
const state = String(q.state ?? '')

// Only a loopback redirect is ever legitimate here (the code/token goes to the
// plugin's local listener). The server enforces this too; the client check is UX.
const isLoopback = computed(() =>
  /^http:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?(\/|$)/i.test(redirectUri),
)
// The guest path needs only a loopback redirect; the account path also needs PKCE.
const paramsValid = computed(() => isLoopback.value)
const accountParamsValid = computed(
  () => codeChallenge !== '' && method === 'S256' && isLoopback.value,
)

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

  finish(res)
}

async function approveGuest(): Promise<void> {
  submitting.value = true
  error.value = null

  const res = await connectGuest({ redirect_uri: redirectUri, state })
  finish(res)
}

function finish(res: { ok: boolean; redirectUri?: string; error?: string }): void {
  submitting.value = false
  if (res.ok && res.redirectUri) {
    done.value = true
    // Hand the code / client token back to the plugin's loopback listener.
    window.location.href = res.redirectUri
  } else {
    error.value = res.error ?? 'Connection failed.'
  }
}
</script>

<template>
  <section class="section">
    <div class="container" style="max-width: 40rem">
      <h1 class="title">Connect the TarotGen Plugin</h1>

      <div v-if="!paramsValid" class="notification is-warning">
        This connection link is missing or has invalid parameters. Please start it from the plugin's
        settings in-game.
      </div>

      <div v-else-if="checkingSession" class="has-text-grey">Checking your session…</div>

      <div v-else-if="done" class="notification is-success">
        <p><strong>Connected.</strong> You can close this tab and return to the game.</p>
      </div>

      <div v-else class="box">
        <p class="mb-4">
          Choose how the <strong>TarotGen FFXIV plugin</strong> connects to TarotGen.io:
        </p>

        <p v-if="error" class="help is-danger mb-3">{{ error }}</p>

        <!-- Account link -->
        <div class="mb-4">
          <p class="mb-1"><strong>Link your account</strong></p>
          <p class="is-size-7 has-text-grey mb-2">
            Unlocks locking readings, favorites, and My Readings — plus sending &amp; receiving
            shared readings. The plugin <strong>cannot</strong> change your password, delete your
            account, or manage other links, and you can revoke it any time from Account Settings.
          </p>
          <button
            v-if="isLoggedIn && accountParamsValid"
            class="button is-primary"
            :class="{ 'is-loading': submitting }"
            :disabled="submitting"
            @click="approve"
          >
            Authorize as {{ currentUser?.display_name }}
          </button>
          <router-link v-else class="button is-primary" :to="loginTo">
            Log in to link your account
          </router-link>
        </div>

        <hr />

        <!-- Guest -->
        <div class="mb-2">
          <p class="mb-1"><strong>Continue as a guest</strong></p>
          <p class="is-size-7 has-text-grey mb-2">
            No account needed. You can send and receive shared readings. You can link an account
            later from the plugin's settings.
          </p>
          <button
            class="button"
            :class="{ 'is-loading': submitting }"
            :disabled="submitting"
            @click="approveGuest"
          >
            Continue as guest
          </button>
        </div>

        <div class="mt-4">
          <router-link class="button is-text" :to="{ name: 'home' }">Cancel</router-link>
        </div>
      </div>
    </div>
  </section>
</template>
