import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { markAuthChecked } from './router'
import { useUser } from './composables/useUser'

import 'bulma/css/bulma.min.css'
import './assets/tokens.css'
import './assets/style.css'

const app = createApp(App)
app.use(router)

// Register the service worker (production only) to enable "Add to Home Screen".
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => { /* non-fatal */ })
    })
}

// Revalidate any existing user session before mounting so router guards (incl.
// admin, which depends on the user's is_admin flag) work immediately.
const { fetchMe } = useUser()
fetchMe().finally(() => {
    markAuthChecked()
    app.mount('#app')
})
