import { expect, test } from '@playwright/test'
import { createAccount, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of the Dashboard's visual summary layer — a
 * presentation-only layer over the already-proven Profit & Loss and
 * Balance Sheet endpoints (AETS-009), not a new report.
 */
test.describe('Dashboard', () => {
  test('shows an empty state with no transactions recorded yet', async ({ page }) => {
    await registerNewUser(page)
    await page.goto('/dashboard')

    await expect(page.getByText('RM0.00').first()).toBeVisible()
    await expect(page.getByText('Nothing recorded in the last 6 months')).toBeVisible()
  })

  test('reflects a recorded Income and Expense in the KPI cards and trend chart', async ({
    page,
  }) => {
    await registerNewUser(page)
    await createAccount(page, '5000', 'Office Supplies', 'Expense')
    await createAccount(page, '1000', 'Cash', 'Asset')
    await createAccount(page, '4000', 'Sales', 'Revenue')

    await page.goto('/manual-entry')

    await page.getByRole('button', { name: 'Income' }).click()
    await page.locator('input[inputmode="decimal"]').fill('500.00')
    await page.locator('form select').nth(0).selectOption({ label: 'Sales' })
    await page.locator('form select').nth(1).selectOption({ label: 'Cash' })
    await page.getByPlaceholder('What was this for?').fill('Sale')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/incomes') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /record/i }).click(),
    ])
    await page.getByText('Recorded.').waitFor()

    await page.getByRole('button', { name: 'Expense' }).click()
    await page.locator('input[inputmode="decimal"]').fill('120.00')
    await page.locator('form select').nth(0).selectOption({ label: 'Office Supplies' })
    await page.locator('form select').nth(1).selectOption({ label: 'Cash' })
    await page.getByPlaceholder('What was this for?').fill('Supplies')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/expenses') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /record/i }).click(),
    ])
    await page.getByText('Recorded.').waitFor()

    await page.goto('/dashboard')

    await expect(page.getByText('RM500.00')).toBeVisible()
    await expect(page.getByText('RM120.00')).toBeVisible()
    await expect(page.getByText('RM380.00').first()).toBeVisible()
  })
})
