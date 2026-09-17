// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },
  // A QA/demo harness (M12), not the product SSR experience — client-side
  // only, so the Sanctum SPA cookie/CSRF dance stays a plain browser flow
  // with no server-side cookie-forwarding complexity to get right.
  ssr: false,
  modules: ['@nuxt/eslint', '@nuxtjs/tailwindcss'],
  css: ['~/assets/css/main.css'],
  // Every component (including app/components/ui/*) auto-imports by
  // its own filename only — no directory-name prefix (Nuxt's default
  // would otherwise register app/components/ui/AppButton.vue as
  // <UiAppButton>, not <AppButton>).
  components: [{ path: '~/components', pathPrefix: false }],
  app: {
    head: {
      htmlAttrs: { lang: 'en' },
      // PWA basics (installable app, no offline-caching sophistication):
      // a static manifest + a hand-written service worker
      // (app/plugins/pwa.client.ts registers it), not the
      // @vite-pwa/nuxt module — that module's dev-mode client
      // injection never fires under this app's own ssr:false + Nuxt 4
      // combination (confirmed: its dev service worker writes to a
      // buildDir the running dev server never serves from, and no
      // amount of config realignment made its Nuxt plugin inject a
      // manifest link or registration script into the page). A static
      // file plus one small plugin is simpler and fully within this
      // project's own control to verify, for the "basics" this item
      // actually needs.
      link: [
        { rel: 'manifest', href: '/manifest.webmanifest' },
        { rel: 'apple-touch-icon', href: '/pwa-icons/apple-touch-icon.png' },
      ],
      meta: [
        { name: 'theme-color', content: '#17171a' },
        // iOS Safari never reads the web manifest for "Add to Home
        // Screen" — these three meta tags are its own, separate
        // opt-in contract for a standalone (no browser chrome) launch.
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-title', content: 'hore.my' },
        { name: 'apple-mobile-web-app-status-bar-style', content: 'black' },
      ],
    },
  },
  eslint: {
    config: {
      // Prettier owns formatting; ESLint stays focused on code-quality rules.
      stylistic: false,
    },
  },
  runtimeConfig: {
    public: {
      // Only the Laravel API's port is configured here — its hostname
      // is derived at request time from the page's own
      // `window.location.hostname` (see `useApi.ts`'s own docblock for
      // why: a fixed cross-host API base breaks Sanctum's SameSite=Lax
      // CSRF cookie the moment the page is reached through a different
      // hostname, e.g. a LAN IP for phone testing).
      apiPort: process.env.NUXT_PUBLIC_API_PORT || '8000',
    },
  },
})
