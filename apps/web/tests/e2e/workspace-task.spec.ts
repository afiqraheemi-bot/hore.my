import { expect, test } from '@playwright/test'
import { createAccount, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of the Work Queue / Task Detail / Human
 * Confirmation flow (ADR-0009, WTS-001) — the committed counterpart
 * to the manual Playwright verification this module's own commit
 * history previously described but never checked in (a real,
 * previously-flagged gap: see `tests/e2e/README.md`).
 */
test.describe('Work Queue and Human Confirmation', () => {
  test('the authenticated landing route opens Work Queue while Manual Entry remains available', async ({
    page,
  }) => {
    await page.goto('/')
    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByRole('heading', { name: 'Your work' })).toBeVisible()

    await page.getByRole('link', { name: 'Manual Entry' }).click()
    await expect(page).toHaveURL(/\/manual-entry$/)
    await expect(page.getByText('Recent activity')).toBeVisible()
  })

  async function submitExpenseTask(page: import('@playwright/test').Page, description: string) {
    await page.goto('/tasks')
    await page.locator('input[inputmode="decimal"]').fill('88.50')
    await page.locator('form select').nth(0).selectOption({ label: 'Office Supplies' })
    await page.locator('form select').nth(1).selectOption({ label: 'Cash' })
    await page.getByPlaceholder('What was this for?').fill(description)

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/tasks') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /submit for review/i }).click(),
    ])
  }

  test.beforeEach(async ({ page }) => {
    await registerNewUser(page)
    await createAccount(page, '5000', 'Office Supplies', 'Expense')
    await createAccount(page, '1000', 'Cash', 'Asset')
  })

  test('submitting a Task lands it in NeedsReview with its Proposal detail', async ({ page }) => {
    await submitExpenseTask(page, 'E2E: office supplies')

    await expect(page.getByText('NeedsReview')).toBeVisible()
    await page.getByRole('tab', { name: /in progress/i }).click()
    await expect(page.getByText('Nothing in progress')).toBeVisible()
    await page.getByRole('tab', { name: /all tasks/i }).click()
    await expect(page.getByText('NeedsReview')).toBeVisible()

    await page.locator('a[href^="/tasks/"]').first().click()
    await expect(page.getByText('NeedsReview', { exact: true })).toBeVisible()
    await expect(page.getByText('RM88.50')).toBeVisible()
    await expect(page.getByText('E2E: office supplies')).toBeVisible()
    await expect(page.getByRole('button', { name: /confirm and post/i })).toBeVisible()
  })

  test('confirming a Task posts a Journal and shows the full transition history', async ({
    page,
  }) => {
    await submitExpenseTask(page, 'E2E: confirm flow')
    await page.locator('a[href^="/tasks/"]').first().click()

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/approve')),
      page.getByRole('button', { name: /confirm and post/i }).click(),
    ])

    await expect(page.getByText('Completed', { exact: true })).toBeVisible()
    await expect(page.getByText('Recorded to your books.')).toBeVisible()
    await expect(page.getByText('Received → Processing')).toBeVisible()
    await expect(page.getByText('NeedsReview → Approved')).toBeVisible()
    await expect(page.getByText('Executing → Completed')).toBeVisible()
  })

  test('rejecting a Task requires a reason and moves it to Rejected', async ({ page }) => {
    await submitExpenseTask(page, 'E2E: reject flow')
    await page.locator('a[href^="/tasks/"]').first().click()

    await page.getByRole('button', { name: /^reject$/i }).click()
    await page.getByPlaceholder('Wrong account, duplicate, etc.').fill('Wrong account chosen.')

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/reject')),
      page.getByRole('button', { name: /^reject$/i }).click(),
    ])

    await expect(page.getByText('Rejected', { exact: true })).toBeVisible()
  })

  test("a Task submitted under one tenant never appears in another tenant's Work Queue", async ({
    page,
  }) => {
    await submitExpenseTask(page, 'E2E: tenant A only')
    await expect(page.getByText('NeedsReview')).toBeVisible()

    await page.getByRole('button', { name: /log out/i }).click()
    await page.waitForURL('**/login')
    await registerNewUser(page)

    await page.goto('/tasks')
    await expect(page.getByText('No Tasks yet')).toBeVisible()
    await expect(page.getByText('E2E: tenant A only')).not.toBeVisible()
  })

  /**
   * IDOR (insecure direct object reference) proof: knowing a Task's
   * id (visible in tenant A's own URL bar, trivially guessable from
   * one's own Tasks, or leaked via a shared link) must not let a
   * *different, authenticated* Tenant load it by navigating straight
   * to its URL — the Work-Queue-listing test above only proves it is
   * not *listed* for another Tenant, which is a weaker property than
   * this: a listing omission alone would not stop a direct URL visit
   * from working if the `/tasks/{id}` route itself were not
   * independently tenant-checked.
   */
  test('a Task is not directly loadable by URL from a different, authenticated tenant', async ({
    page,
  }) => {
    await submitExpenseTask(page, 'E2E: tenant A direct object')
    await page.locator('a[href^="/tasks/"]').first().click()
    // NuxtLink navigation is client-side (Vue Router) — page.url() read
    // immediately after click() can race the URL actually updating.
    await page.waitForURL(/\/tasks\/[^/]+$/)
    const tenantATaskUrl = page.url()

    await page.getByRole('button', { name: /log out/i }).click()
    await page.waitForURL('**/login')
    await registerNewUser(page)

    await page.goto(tenantATaskUrl)

    await expect(page.getByText('Failed to load this Task.')).toBeVisible()
    await expect(page.getByText('E2E: tenant A direct object')).not.toBeVisible()
    await expect(page.getByText('RM88.50')).not.toBeVisible()
  })
})
