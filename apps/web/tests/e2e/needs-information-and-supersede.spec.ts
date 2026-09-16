import { expect, test } from '@playwright/test'
import { createAccount, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of WTS-001 v3.0.0's two new flows (TSK-013,
 * TSK-014) — the "NeedsInformation dan edit/supersede Proposal UX"
 * target-experience gap: deferring the Account decision at submission
 * time, completing it later, and editing a Task under review by
 * superseding it with a correction.
 */
test.describe('NeedsInformation and edit/supersede', () => {
  test.beforeEach(async ({ page }) => {
    await registerNewUser(page)
    await createAccount(page, '5000', 'Office Supplies', 'Expense')
    await createAccount(page, '1000', 'Cash', 'Asset')
  })

  test('deferring the account decision lands the task in NeedsInformation, and providing it later completes the task', async ({
    page,
  }) => {
    await page.goto('/')
    await page.locator('input[inputmode="decimal"]').fill('30.00')
    await page.getByPlaceholder('What was this for?').fill('E2E: not sure which account yet')
    await page.getByText('Not sure which accounts yet? Decide later.').click()

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/tasks') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /submit for review/i }).click(),
    ])

    await expect(page.getByText('NeedsInformation')).toBeVisible()

    await page.locator('a[href^="/tasks/"]').first().click()
    await expect(page.getByRole('heading', { name: 'Needs more information' })).toBeVisible()
    await expect(page.getByText('RM30.00')).toBeVisible()

    await page.locator('form select').nth(0).selectOption({ label: '5000 — Office Supplies' })
    await page.locator('form select').nth(1).selectOption({ label: '1000 — Cash' })

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/provide-information')),
      page.getByRole('button', { name: /submit for review/i }).click(),
    ])

    await expect(page.getByText('NeedsReview', { exact: true })).toBeVisible()
    await expect(page.getByText('RM30.00')).toBeVisible()

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/approve')),
      page.getByRole('button', { name: /confirm and post/i }).click(),
    ])

    await expect(page.getByText('Completed', { exact: true })).toBeVisible()
  })

  async function submitExpenseTask(page: import('@playwright/test').Page, description: string) {
    await page.goto('/')
    await page.locator('input[inputmode="decimal"]').fill('40.00')
    await page.locator('form select').nth(0).selectOption({ label: '5000 — Office Supplies' })
    await page.locator('form select').nth(1).selectOption({ label: '1000 — Cash' })
    await page.getByPlaceholder('What was this for?').fill(description)

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/tasks') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /submit for review/i }).click(),
    ])
  }

  test('editing a task under review supersedes it and navigates to the linked correction', async ({
    page,
  }) => {
    await createAccount(page, '1010', 'Savings', 'Asset')
    await submitExpenseTask(page, 'E2E: wrong payment account')

    await page.locator('a[href^="/tasks/"]').first().click()
    await page.waitForURL(/\/tasks\/[^/]+$/)
    const originalUrl = page.url()

    await page.getByRole('button', { name: /^edit$/i }).click()
    await page.locator('input[inputmode="decimal"]').fill('45.00')
    await page.locator('form select').nth(1).selectOption({ label: '1010 — Savings' })

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/supersede')),
      page.getByRole('button', { name: /save correction/i }).click(),
    ])

    await page.waitForURL((url) => url.href !== originalUrl && /\/tasks\/[^/]+$/.test(url.pathname))
    await expect(page.getByText('NeedsReview', { exact: true })).toBeVisible()
    await expect(page.getByText('RM45.00')).toBeVisible()
    await expect(page.locator('p:has-text("Correction of")')).toBeVisible()

    await page.goto(originalUrl)
    await expect(page.getByText('Superseded', { exact: true })).toBeVisible()
    await expect(page.getByText('This Task was superseded by a correction.')).toBeVisible()
  })
})
