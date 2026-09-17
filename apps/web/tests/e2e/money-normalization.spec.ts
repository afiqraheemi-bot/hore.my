import { expect, test } from '@playwright/test'
import { createAccount, createCustomer, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof that an amount typed without cents (e.g. "390",
 * the natural thing to type on a phone's numeric keypad) still
 * succeeds end to end. Every backend Money-accepting endpoint requires
 * the exact `\d+\.\d{2}` decimal-string shape (AETS-003) — without
 * `normalizeMoney()` at each submission site, "390" reaches the API
 * as-is, fails that strict format validation, and the user sees only
 * a generic "Failed to..." message with no indication that ".00"
 * would have fixed it.
 */
test.describe('Money field normalization', () => {
  test('a payment amount typed without decimals still records correctly', async ({ page }) => {
    await registerNewUser(page)
    await createAccount(page, '1000', 'Bank', 'Asset')
    await createAccount(page, '1100', 'Accounts Receivable', 'Asset')
    await createCustomer(page, 'E2E Test Customer')

    await page.goto('/payments')
    await page.getByRole('button', { name: /record payment/i }).click()
    await page.locator('form select').nth(0).selectOption({ label: 'E2E Test Customer' })
    await page.getByPlaceholder('300.00').fill('390')
    await page.locator('input[type="date"]').fill('2026-09-17')
    await page.locator('form select').nth(1).selectOption({ label: 'Bank' })
    await page.locator('form select').nth(2).selectOption({ label: 'Accounts Receivable' })

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/payments') && res.request().method() === 'POST',
      ),
      page.locator('form button[type="submit"]').click(),
    ])

    await expect(page.getByText('Failed to record payment')).not.toBeVisible()
    await expect(page.getByText('RM390.00').first()).toBeVisible()
  })

  test('an invoice line unit price typed without decimals still creates correctly', async ({
    page,
  }) => {
    await registerNewUser(page)
    await createAccount(page, '1100', 'Accounts Receivable', 'Asset')
    await createAccount(page, '4100', 'Service Revenue', 'Revenue')
    await createCustomer(page, 'E2E Test Customer')

    await page.goto('/invoices')
    await page.getByRole('button', { name: /new invoice/i }).click()
    await page.locator('form select').first().selectOption({ label: 'E2E Test Customer' })
    await page.locator('input[type="date"]').first().fill('2026-12-31')
    await page.locator('form select').nth(1).selectOption({ label: 'Accounts Receivable' })
    await page.locator('form select').nth(2).selectOption({ label: 'Service Revenue' })
    await page.getByPlaceholder('Description').fill('Consulting')
    await page.getByPlaceholder('Qty').fill('1')
    await page.getByPlaceholder('Unit price').fill('250')

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/invoices') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /save as draft/i }).click(),
    ])

    await expect(page.getByText('Failed to create invoice draft')).not.toBeVisible()
    await expect(page.getByText(/RM250\.00/).first()).toBeVisible()
  })
})
