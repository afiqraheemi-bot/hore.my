import type { Page } from '@playwright/test'

/**
 * Registers a fresh Tenant/User and leaves the browser authenticated
 * on `/business-profile` (register.vue's own post-registration
 * redirect) — the starting point every E2E spec needs, since nothing
 * in this suite depends on pre-seeded demo/admin accounts (avoids
 * shared-state coupling between specs and parallel runs).
 */
export async function registerNewUser(page: Page): Promise<{ email: string }> {
  const email = `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.my`

  await page.goto('/register')
  await page.locator('input[type="text"]').first().fill('E2E Test User')
  await page.locator('input[type="email"]').first().fill(email)
  await page.locator('input[type="password"]').nth(0).fill('password123')
  await page.locator('input[type="password"]').nth(1).fill('password123')
  await page.locator('input[type="checkbox"]').first().check()

  await Promise.all([
    page.waitForResponse(
      (res) => res.url().includes('/api/v1/register') && res.request().method() === 'POST',
    ),
    page.locator('button[type="submit"]').first().click(),
  ])
  await page.waitForURL('**/business-profile')

  return { email }
}

/**
 * Creates one Account via the `/accounts` page's own inline form —
 * the same real HTTP round trip a user creating a Chart of Accounts
 * entry performs, not a direct API call bypassing the UI.
 */
export async function createAccount(
  page: Page,
  code: string,
  name: string,
  type: string,
): Promise<void> {
  await page.goto('/accounts')
  await page.getByRole('button', { name: /new account/i }).click()
  await page.locator('input').nth(0).fill(code)
  await page.locator('input').nth(1).fill(name)
  await page.locator('form select').first().selectOption(type)

  await Promise.all([
    page.waitForResponse(
      (res) => res.url().endsWith('/api/v1/accounts') && res.request().method() === 'POST',
    ),
    page.locator('form button[type="submit"]').click(),
  ])
}
