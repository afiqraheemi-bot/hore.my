// Minimal hand-written service worker (PWA basics only) — a runtime,
// network-first cache for this app's own same-origin static assets,
// so a repeat visit still loads if the network is briefly unavailable.
// Deliberately NOT precaching a build-time asset list (this project
// has no Workbox-style manifest-injection step) and NEVER touching
// the Laravel API, which is always a different origin/port anyway —
// no API response is ever cached, no offline write/background sync
// of any kind is attempted or claimed.

const CACHE_NAME = 'hore-my-shell-v1'

self.addEventListener('install', () => {
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))),
      )
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  const { request } = event

  // Only cache safe, same-origin GET requests — the API is always a
  // different origin (see useApi.ts), so this never touches it.
  if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) {
    return
  }

  event.respondWith(
    fetch(request)
      .then((response) => {
        const responseClone = response.clone()
        caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone))
        return response
      })
      .catch(() => caches.match(request)),
  )
})
