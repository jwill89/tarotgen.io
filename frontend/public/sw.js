/*
 * Minimal service worker.
 *
 * Its only job is to make the app installable ("Add to Home Screen"). It uses a
 * pass-through fetch handler — it deliberately does NOT cache app assets — so a
 * new deploy is never served stale. Activate immediately and take control of
 * open pages.
 */
self.addEventListener('install', () => self.skipWaiting())

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim())
})

// Present but pass-through: requests go to the network as usual.
self.addEventListener('fetch', () => {})
