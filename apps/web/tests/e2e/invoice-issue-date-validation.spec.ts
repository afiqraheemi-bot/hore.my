import { expect, test } from '@playwright/test'
import { createAccount, createCustomer, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof that issuing an Invoice whose due date is before
 * the issue date shows a message that actually names the problem.
 * The previous message ("it may be empty or already issued") guessed
 * wrong for this — a real user hit exactly this with a Draft invoice
 * created with today's date as its due date, then tried to issue it
 * the next day (the per-row issue-date picker defaults to "today",
 * which had by then moved past the fixed due date) and had no way to
 * tell from the error what to actually change.
 */
test('issuing an invoice with a due date before the issue date names the real problem', async ({
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

  await page.getByRole('button', { name: 'Issue' }).click()
  await expect(page.getByText(/issue date.*not after the due date/i)).toBeVisible()

  // Backdating the issue date to on-or-before the due date resolves it.
  await page.locator('input[type="date"]').last().fill('2020-01-01')
  await Promise.all([
    page.waitForResponse(
      (res) => res.url().includes('/issue') && res.request().method() === 'POST',
    ),
    page.getByRole('button', { name: 'Issue' }).click(),
  ])
  await expect(page.getByText(/issue date.*not after the due date/i)).not.toBeVisible()
  await expect(page.getByText('Issued').first()).toBeVisible()
})
