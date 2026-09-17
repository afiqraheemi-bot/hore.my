import { expect, test } from '@playwright/test'
import { registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of PWA basics — an installable app manifest and
 * a registered service worker, hand-written rather than via
 * @vite-pwa/nuxt (which never successfully injected anything under
 * this app's own ssr:false + Nuxt 4 combination — see
 * app/plugins/pwa.client.ts's own docblock). No offline-write or
 * background-sync capability is claimed; this proves installability
 * only.
 */
test.describe('PWA basics', () => {
  test('the app manifest, theme color, and service worker are all wired up', async ({ page }) => {
    await registerNewUser(page)

    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute(
      'href',
      '/manifest.webmanifest',
    )
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#17171a')

    const manifestResponse = await page.request.get('/manifest.webmanifest')
    expect(manifestResponse.status()).toBe(200)
    const manifest = await manifestResponse.json()
    expect(manifest.name).toBe('hore.my')
    expect(manifest.display).toBe('standalone')
    expect(manifest.icons.length).toBeGreaterThan(0)

    await expect
      .poll(
        async () =>
          page.evaluate(async () => (await navigator.serviceWorker.getRegistrations()).length),
        { timeout: 10_000 },
      )
      .toBeGreaterThan(0)
  })
})
