import type { BeforeInstallPromptEvent } from '~/composables/useInstallPrompt'

/**
 * PWA basics — registers the hand-written service worker
 * (public/sw.js) so the app is installable ("Add to Home Screen" /
 * the browser's install-icon affordance) and a repeat visit still
 * loads its own static assets if the network briefly drops. Never
 * touches the Laravel API (always a different origin, see
 * useApi.ts) — no offline write, no background sync.
 *
 * Also captures Chromium's `beforeinstallprompt` event exactly once,
 * app-wide, into the shared state `useInstallPrompt()` reads —
 * registering the listener here (not inside the composable itself)
 * means calling that composable from multiple components never adds
 * duplicate listeners.
 *
 * `.client.ts` suffix + this app's own `ssr: false` mean this only
 * ever runs in the browser, exactly where these APIs exist.
 */
export default defineNuxtPlugin(() => {
  if ('serviceWorker' in navigator) {
    // Registered directly, not deferred to the `load` event: this
    // plugin already only ever runs client-side, well after the
    // browser has started rendering, and `register()` itself is safe
    // to call at any point — deferring to `load` risks missing the
    // event entirely if it has already fired by the time this runs.
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Installability is a progressive enhancement, not a
      // requirement — a registration failure (e.g. an unsupported
      // browser context) never blocks the app itself.
    })
  }

  const deferredEvent = useState<BeforeInstallPromptEvent | null>('pwa-install-event', () => null)
  const installed = useState<boolean>('pwa-installed', () => false)

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferredEvent.value = event as BeforeInstallPromptEvent
  })
  window.addEventListener('appinstalled', () => {
    installed.value = true
    deferredEvent.value = null
  })
})
