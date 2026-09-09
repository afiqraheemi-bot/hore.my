import { expect, test } from '@playwright/test'
import { login, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of Sanctum SPA cookie authentication (ADR-0008)
 * end to end — the exact scenario that surfaced a real production bug
 * this session found and fixed (a fixed cross-host API base breaking
 * login via SameSite=Lax CSRF rejection the moment the page's own
 * hostname differed from it). This spec is what would have caught
 * that regression automatically.
 */
test.describe('authentication', () => {
  test('registering lands on business profile and the sidebar shows the account', async ({
    page,
  }) => {
    const { email } = await registerNewUser(page)

    await expect(page).toHaveURL(/business-profile/)
    await page.goto('/')
    await expect(page).toHaveURL(/\/tasks$/)
    await expect(page.getByText(email)).toBeVisible()
  })

  test('logging out then back in with the same credentials succeeds', async ({ page }) => {
    const { email, password } = await registerNewUser(page)
    await page.goto('/')

    await page.getByRole('button', { name: /log out/i }).click()
    await expect(page).toHaveURL(/login/)

    // The bug this session actually found and fixed (a fixed
    // cross-host API base breaking Sanctum's SameSite=Lax CSRF cookie)
    // only surfaces on this exact second step — a real login POST
    // after a real logout, not merely landing on /login. An earlier
    // version of this spec stopped at the assertion above and never
    // actually re-authenticated, so it could not have caught that
    // regression despite its own name claiming otherwise.
    await login(page, email, password)

    await expect(page).toHaveURL(/\/tasks$/)
    await expect(page.getByText(email)).toBeVisible()
  })

  test('an incorrect password shows a clear error, not a crash', async ({ page }) => {
    await page.goto('/login')
    await page.locator('input[type="email"]').fill('nonexistent-user@example.my')
    await page.locator('input[type="password"]').fill('wrong-password')

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/api/v1/login')),
      page.locator('button[type="submit"]').click(),
    ])

    await expect(page.getByText(/do not match our records/i)).toBeVisible()
  })
})
