import { expect, type Page } from '@playwright/test'

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

/**
 * Registers an existing Asset Account as a Bank Account via the
 * `/bank-accounts` page's own inline form — the same real HTTP round
 * trip a user linking a real bank account performs. This is what
 * marks an Account "cash-equivalent" for the Cash Flow Statement
 * (AETS-009 §22) — without it, no Journal touching that Account is
 * ever considered by that report.
 */
export async function registerBankAccount(
  page: Page,
  accountName: string,
  bankName: string,
): Promise<void> {
  await page.goto('/bank-accounts')
  await page.getByRole('button', { name: /register bank account/i }).click()
  await page.locator('form select').first().selectOption({ label: accountName })
  await page.getByPlaceholder('Maybank').fill(bankName)

  await Promise.all([
    page.waitForResponse(
      (res) => res.url().endsWith('/api/v1/bank-accounts') && res.request().method() === 'POST',
    ),
    page.locator('form button[type="submit"]').click(),
  ])
}

/**
 * Creates a Task and drives it all the way to `NeedsReview`, leaving
 * the browser on that Task's own detail page — the only door into the
 * Task/Work Queue pipeline now that a fully-filled composer posts
 * directly (2026-09-21, Founder-directed follow-up to UX-01; see
 * AppComposer.vue's own docblock). Deferring the Account decision at
 * submission time lands it in `NeedsInformation`; completing it via
 * the Task detail page's own form transitions
 * `NeedsInformation -> NeedsReview` — the same two real HTTP round
 * trips (`POST /api/v1/tasks`, `POST .../provide-information`) a user
 * who genuinely wasn't sure yet, then came back, would make.
 */
export async function createTaskInNeedsReview(
  page: Page,
  amount: string,
  primaryAccountLabel: string,
  secondaryAccountLabel: string,
  description: string,
): Promise<void> {
  await page.goto('/')
  await page.locator('input[inputmode="decimal"]').fill(amount)
  await page.getByPlaceholder('What was this for?').fill(description)
  await page.getByText('Not sure which accounts yet? Decide later.').click()

  await Promise.all([
    page.waitForResponse(
      (res) => res.url().endsWith('/api/v1/tasks') && res.request().method() === 'POST',
    ),
    page.getByRole('button', { name: /save for later/i }).click(),
  ])
  await expect(page.getByText('NeedsInformation')).toBeVisible()

  // Client-side (Vue Router) navigation — click() resolves as soon as
  // the event fires, not once the destination has rendered. Without
  // waiting for content unique to the Task detail page first, the
  // form selects below can race the still-visible composer's own
  // (unrelated) selects on the page being navigated away from.
  await page.locator('a[href^="/tasks/"]').first().click()
  await expect(page.getByRole('heading', { name: 'Needs more information' })).toBeVisible()
  await page.locator('form select').nth(0).selectOption({ label: primaryAccountLabel })
  await page.locator('form select').nth(1).selectOption({ label: secondaryAccountLabel })

  await Promise.all([
    page.waitForResponse((res) => res.url().includes('/provide-information')),
    page.getByRole('button', { name: /submit for review/i }).click(),
  ])
}
