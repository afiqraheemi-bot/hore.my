import { expect, test } from '@playwright/test'
import {
  createAccount,
  createCustomer,
  registerBankAccount,
  registerNewUser,
} from './support/fixtures'

/**
 * Real-browser proof of the Dashboard's read-only presentation over
 * `/api/v1/dashboard`, whose figures remain sourced from the
 * authoritative reporting queries.
 */
test.describe('Dashboard', () => {
  test('shows an empty state with no transactions recorded yet', async ({ page }) => {
    await registerNewUser(page)
    await page.goto('/dashboard')

    await expect(page.getByText('RM0.00').first()).toBeVisible()
    await expect(page.getByText('Nothing recorded in the last 6 months')).toBeVisible()
    await expect(page.getByText('All caught up')).toBeVisible()
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

    await expect(page.getByText('RM500.00').first()).toBeVisible()
    await expect(page.getByText('RM120.00').first()).toBeVisible()
    await expect(page.getByText('RM380.00').first()).toBeVisible()
    await expect(page.getByRole('img', { name: /income RM500\.00/i })).toBeVisible()
    await expect(page.getByRole('img', { name: /expenses RM120\.00/i })).toBeVisible()

    await page.getByText('View exact monthly figures').click()
    await expect(page.getByRole('table')).toContainText('RM500.00')

    await page.setViewportSize({ width: 390, height: 844 })
    await expect(page.getByText('Your financial overview')).toBeVisible()
    const hasHorizontalPageOverflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    )
    expect(hasHorizontalPageOverflow).toBe(false)
  })

  test('cash flow chart stays empty for an unlinked Account and reflects a Bank Account once linked', async ({
    page,
  }) => {
    await registerNewUser(page)
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

    // Cash is not yet linked to a Bank Account: the Cash Flow
    // Statement's own "cash-equivalent" definition (AETS-009 §22)
    // excludes it, so the dashboard's Cash flow section must show its
    // own empty state even though the Income above already appears in
    // the Income/expenses trend chart.
    await page.goto('/dashboard')
    await expect(page.getByText('RM500.00').first()).toBeVisible()
    await expect(page.getByText('No cash movement recorded yet')).toBeVisible()

    await registerBankAccount(page, 'Cash', 'Test Bank')

    await page.goto('/dashboard')
    await expect(page.getByText('No cash movement recorded yet')).not.toBeVisible()
    await expect(page.getByRole('img', { name: /net cash in RM500\.00/i })).toBeVisible()

    await page.locator('text=View exact monthly figures').last().click()
    const cashFlowTable = page.getByRole('table').last()
    await expect(cashFlowTable).toContainText('RM500.00')
  })

  test('the overdue invoices attention item deep-links to the Aging Report tab, not the default Trial Balance', async ({
    page,
  }) => {
    await registerNewUser(page)
    await createAccount(page, '1100', 'Accounts Receivable', 'Asset')
    await createAccount(page, '4100', 'Service Revenue', 'Revenue')
    await createCustomer(page, 'Pustaka Azhar')

    await page.goto('/invoices')
    await page.getByRole('button', { name: /new invoice/i }).click()
    await page.locator('form select').first().selectOption({ label: 'Pustaka Azhar' })
    await page.locator('input[type="date"]').first().fill('2020-01-01')
    await page.locator('form select').nth(1).selectOption({ label: 'Accounts Receivable' })
    await page.locator('form select').nth(2).selectOption({ label: 'Service Revenue' })
    await page.getByPlaceholder('Description').fill('Tudung')
    await page.getByPlaceholder('Qty').fill('10')
    await page.getByPlaceholder('Unit price').fill('26.90')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/invoices') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /save as draft/i }).click(),
    ])
    await page.locator('input[type="date"]').last().fill('2020-01-01')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().includes('/issue') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: 'Issue' }).click(),
    ])

    await page.goto('/dashboard')
    const overdueLink = page.getByRole('link', { name: /overdue invoices/i })
    await expect(overdueLink).toBeVisible()
    await overdueLink.click()

    await page.waitForURL(/\/reports\?tab=Aging(\+|%20)Report/)
    await expect(page.getByRole('button', { name: 'Aging Report' })).toHaveClass(/border-accent/)
    await expect(page.locator('table')).toContainText('RM 269.00')

    // The Aging Report row's own "Record payment" shortcut carries the
    // Invoice's Customer straight into the Payments form pre-selected —
    // closing the gap where a user had to re-find the right Customer
    // themselves in a plain dropdown.
    await page.getByRole('link', { name: 'Record payment' }).click()
    await page.waitForURL(/\/payments\?customer_id=/)
    await expect(page.locator('form select').first()).toHaveValue(/.+/)
    const selectedLabel = await page
      .locator('form select')
      .first()
      .evaluate((el: HTMLSelectElement) => el.selectedOptions[0]?.textContent)
    expect(selectedLabel).toBe('Pustaka Azhar')
  })
})
