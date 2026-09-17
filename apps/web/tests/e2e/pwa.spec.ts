import { expect, test } from '@playwright/test'
import { registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of PWA basics — an installable app manifest, a
 * registered service worker, iOS-specific meta tags, and a custom
 * "Install app" button that reacts to Chromium's own
 * `beforeinstallprompt` event (never fabricated: the button stays
 * hidden until the browser genuinely offers to install). Hand-written
 * rather than via @vite-pwa/nuxt (which never successfully injected
 * anything under this app's own ssr:false + Nuxt 4 combination — see
 * app/plugins/pwa.client.ts's own docblock). No offline-write or
 * background-sync capability is claimed.
 */
test.describe('PWA basics', () => {
  test('the app manifest, iOS meta tags, and service worker are all wired up', async ({ page }) => {
    await registerNewUser(page)

    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute(
      'href',
      '/manifest.webmanifest',
    )
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#17171a')
    await expect(page.locator('meta[name="apple-mobile-web-app-capable"]')).toHaveAttribute(
      'content',
      'yes',
    )
    await expect(
      page.locator('meta[name="apple-mobile-web-app-status-bar-style"]'),
    ).toHaveAttribute('content', 'black')

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

  test('the custom Install app button appears only once the browser offers to install, and triggers the real prompt', async ({
    page,
  }) => {
    await registerNewUser(page)

    await expect(page.getByRole('button', { name: 'Install app' })).toHaveCount(0)

    // Simulates Chromium's own beforeinstallprompt — this app never
    // fabricates the affordance itself.
    await page.evaluate(() => {
      const event = new Event('beforeinstallprompt', { cancelable: true }) as Event & {
        prompt: () => Promise<void>
        userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
      }
      event.prompt = () => {
        ;(window as unknown as { __promptCalled: boolean }).__promptCalled = true
        return Promise.resolve()
      }
      event.userChoice = Promise.resolve({ outcome: 'accepted' })
      window.dispatchEvent(event)
    })

    const installButton = page.getByRole('button', { name: 'Install app' })
    await expect(installButton).toBeVisible()

    await installButton.click()
    const promptCalled = await page.evaluate(
      () => (window as unknown as { __promptCalled?: boolean }).__promptCalled === true,
    )
    expect(promptCalled).toBe(true)

    // An accepted install hides the button — it never lingers once
    // there is nothing left to prompt.
    await expect(installButton).toHaveCount(0)
  })
})
