import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end configuration for the committed Playwright suite
 * (`tests/e2e/`) — behavioral proof of the flows this repository's
 * own commit history had previously only verified manually, ad hoc,
 * and outside version control (a real, previously-flagged gap: see
 * `tests/e2e/README.md`).
 *
 * Assumes the full stack (`docker compose up`) is already running —
 * `baseURL` and the API it talks to (derived client-side from
 * `window.location.hostname`, see `app/composables/useApi.ts`) both
 * resolve through `localhost`, matching how a developer or CI runner
 * reaches the same containers. This config does not start the stack
 * itself; CI's own workflow step does that before invoking
 * `playwright test`, exactly as a developer running this suite
 * locally must.
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  timeout: 30_000,
  use: {
    baseURL: 'http://localhost:3000',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
