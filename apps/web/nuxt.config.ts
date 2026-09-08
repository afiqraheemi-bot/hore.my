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
      // The Laravel API's origin (ADR-0008: separate origin, Sanctum SPA
      // cookie auth) — never bundled with a trailing slash.
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000',
    },
  },
})
