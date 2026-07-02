<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { apiFetch } from '@/composables/apiClient'
import { endpoints } from '@/api/endpoints'

/**
 * Cloudflare Turnstile widget. Self-configuring: it fetches the public site key
 * from `/api/config` and renders only when Turnstile is enabled server-side, so
 * environments without keys (e.g. local dev) simply show nothing.
 *
 * Two-way bindings:
 *   v-model            → the challenge token (empty until solved/after expiry)
 *   v-model:enabled    → whether Turnstile is configured (lets the parent gate submit)
 *
 * Exposes reset() so the parent can clear a spent token after a failed attempt
 * (Turnstile tokens are single-use).
 */

interface TurnstileApi {
  render: (el: HTMLElement, opts: Record<string, unknown>) => string
  reset: (id?: string) => void
  remove: (id?: string) => void
}

declare global {
  interface Window {
    turnstile?: TurnstileApi
  }
}

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'

// Load the Turnstile script once per page, shared across any widget instances.
let scriptPromise: Promise<void> | null = null
function loadScript(): Promise<void> {
  if (window.turnstile) return Promise.resolve()
  if (scriptPromise) return scriptPromise

  scriptPromise = new Promise<void>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = SCRIPT_SRC
    script.async = true
    script.defer = true
    script.onload = () => resolve()
    script.onerror = () => {
      scriptPromise = null
      reject(new Error('Failed to load Turnstile.'))
    }
    document.head.appendChild(script)
  })
  return scriptPromise
}

const token = defineModel<string>({ default: '' })
const enabled = defineModel<boolean>('enabled', { default: false })

const container = ref<HTMLDivElement | null>(null)
let widgetId: string | null = null

onMounted(async () => {
  const config = await apiFetch<{ turnstile_sitekey?: string | null }>(endpoints.config)
  const siteKey = config?.turnstile_sitekey
  if (!siteKey || !container.value) {
    return
  }

  enabled.value = true

  try {
    await loadScript()
  } catch {
    return
  }

  if (!window.turnstile || !container.value) return

  widgetId = window.turnstile.render(container.value, {
    sitekey: siteKey,
    theme: 'auto',
    callback: (t: string) => {
      token.value = t
    },
    'expired-callback': () => {
      token.value = ''
    },
    'error-callback': () => {
      token.value = ''
    },
  })
})

onBeforeUnmount(() => {
  if (widgetId && window.turnstile) {
    window.turnstile.remove(widgetId)
  }
})

function reset(): void {
  token.value = ''
  if (widgetId && window.turnstile) {
    window.turnstile.reset(widgetId)
  }
}

defineExpose({ reset })
</script>

<template>
  <div v-show="enabled" class="field">
    <div ref="container"></div>
  </div>
</template>
