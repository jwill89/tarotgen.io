import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { markAuthChecked } from './router'
import { useUser } from './composables/useUser'
import { FontAwesomeIcon, FontAwesomeLayers } from '@fortawesome/vue-fontawesome'

import 'bulma/css/bulma.min.css'
import './assets/tokens.css'
import './assets/fonts.css'
import './assets/style.css'
// Self-hosted FontAwesome: imports the icon set + core CSS and exposes the
// byPrefixAndName lookup used by the <FontAwesomeIcon> components below.
import './fontawesome'

const app = createApp(App)
app.use(router)

// Last line of defense: log unhandled render/setup errors instead of letting a
// single failing component (e.g. a <RouterLink> to an unregistered route) throw
// out of mount and blank the entire app.
app.config.errorHandler = (err, _instance, info) => {
  console.error(`[app] Unhandled error during ${info}:`, err)
}

// Register the FA Vue components globally so templates can use them without a
// per-file import (icons are passed via :icon="byPrefixAndName.xxx['name']").
app.component('FontAwesomeIcon', FontAwesomeIcon)
app.component('FontAwesomeLayers', FontAwesomeLayers)

// Register the service worker (production only) to enable "Add to Home Screen".
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      /* non-fatal */
    })
  })
}

/** Fade out and remove the index.html boot loader once the app is on screen. */
function hideBootLoader(): void {
  const el = document.getElementById('app-loading')
  if (!el) return
  el.classList.add('is-hiding')
  el.addEventListener('transitionend', () => el.remove(), { once: true })
  // Fallback in case the transition is skipped (e.g. reduced-motion / no repaint).
  setTimeout(() => el.remove(), 600)
}

// Revalidate any existing user session before mounting so router guards (incl.
// admin, which depends on the user's is_admin flag) work immediately.
const { fetchMe } = useUser()
void fetchMe().finally(async () => {
  markAuthChecked()
  // Wait for the initial route's lazily-loaded component (and its guards) to
  // resolve before mounting, so the app shell and the view paint together
  // rather than the background/navbar flashing in ahead of the content.
  await router.isReady()
  app.mount('#app')
  hideBootLoader()
})
