import type { Page } from '@playwright/test'

/**
 * Registers a fresh Tenant/User and leaves the browser authenticated
 * on `/business-profile` (register.vue's own post-registration
 * redirect) — the starting point every E2E spec needs, since nothing
 * in this suite depends on pre-seeded demo/admin accounts (avoids
 * shared-state coupling between specs and parallel runs).
 */
export const E2E_PASSWORD = 'password123'

export async function registerNewUser(page: Page): Promise<{ email: string; password: string }> {
  const email = `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.my`

  await page.goto('/register')
  await page.locator('input[type="text"]').first().fill('E2E Test User')
  await page.locator('input[type="email"]').first().fill(email)
  await page.locator('input[type="password"]').nth(0).fill(E2E_PASSWORD)
  await page.locator('input[type="password"]').nth(1).fill(E2E_PASSWORD)
  await page.locator('input[type="checkbox"]').first().check()

  await Promise.all([
    page.waitForResponse(
      (res) => res.url().includes('/api/v1/register') && res.request().method() === 'POST',
    ),
    page.locator('button[type="submit"]').first().click(),
  ])
  await page.waitForURL('**/business-profile')

  return { email, password: E2E_PASSWORD }
}

/**
 * Logs in with an already-registered email/password from the login
 * page and waits for the real POST /api/v1/login round trip to
 * resolve — used by specs that need to prove a *second* session
 * actually authenticates, not just that the login form is reachable.
 */
export async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login')
  await page.locator('input[type="email"]').fill(email)
  await page.locator('input[type="password"]').fill(password)

  await Promise.all([
    page.waitForResponse(
      (res) => res.url().includes('/api/v1/login') && res.request().method() === 'POST',
    ),
    page.locator('button[type="submit"]').click(),
  ])
}

/**
 * Creates one Account via the `/accounts` page's own inline form —
 * the same real HTTP round trip a user creating a Chart of Accounts
 * entry performs, not a direct API call bypassing the UI.
 */
/**
 * Creates one Customer via the `/customers` page's own inline form —
 * the same real HTTP round trip a user adding a Customer performs.
 */
export async function createCustomer(page: Page, name: string): Promise<void> {
  await page.goto('/customers')
  await page.getByRole('button', { name: /new customer/i }).click()
  await page.getByPlaceholder('Kedai Runcit Aminah').fill(name)

  await Promise.all([
    page.waitForResponse(
      (res) => res.url().endsWith('/api/v1/customers') && res.request().method() === 'POST',
    ),
    page.locator('form').getByRole('button', { name: /add/i }).click(),
  ])
}

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
