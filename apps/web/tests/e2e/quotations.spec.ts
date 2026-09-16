import { expect, test } from '@playwright/test'
import { createAccount, createCustomer, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of the Quotation lifecycle (AETS-016) — item #6
 * of the target-experience gap list ("Quotation, compliance pack dan
 * export menyeluruh"): Draft -> Send -> Accept -> Convert to a real
 * Draft Invoice, which then issues exactly like any other Invoice.
 */
test.describe('Quotations', () => {
  test.beforeEach(async ({ page }) => {
    await registerNewUser(page)
    await createCustomer(page, 'Kedai Runcit Aminah')
    await createAccount(page, '1100', 'Accounts Receivable', 'Asset')
    await createAccount(page, '4100', 'Service Revenue', 'Revenue')
  })

  test('drafting, sending, accepting, and converting a quotation into an invoice', async ({
    page,
  }) => {
    await page.goto('/quotations')
    await page.getByRole('button', { name: /new quotation/i }).click()

    await page.locator('form select').first().selectOption({ label: 'Kedai Runcit Aminah' })
    await page.locator('input[type="date"]').first().fill('2026-12-31')
    await page.getByPlaceholder('Description').fill('Consulting')
    await page.getByPlaceholder('Qty').fill('2')
    await page.getByPlaceholder('Unit price').fill('150.00')

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/quotations') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /save as draft/i }).click(),
    ])

    await expect(page.getByText('Draft', { exact: true }).last()).toBeVisible()
    await expect(page.getByText('RM300.00', { exact: true })).toBeVisible()

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/send')),
      page.getByRole('button', { name: /^send$/i }).click(),
    ])
    await expect(page.getByText('QUO-000001')).toBeVisible()
    await expect(page.getByText('Sent', { exact: true })).toBeVisible()

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/accept')),
      page.getByRole('button', { name: /mark accepted/i }).click(),
    ])
    await expect(page.getByText('Accepted', { exact: true })).toBeVisible()

    await page.getByRole('button', { name: /convert to invoice/i }).click()
    // The convert panel's own two account selects and due-date input.
    const convertPanel = page.locator('div').filter({ hasText: 'Invoice due date' }).last()
    await convertPanel.locator('select').nth(0).selectOption({ label: 'Accounts Receivable' })
    await convertPanel.locator('select').nth(1).selectOption({ label: 'Service Revenue' })

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/convert-to-invoice')),
      page.getByRole('button', { name: /create draft invoice/i }).click(),
    ])

    await expect(page.getByText('Converted', { exact: true })).toBeVisible()

    await page.getByRole('link', { name: /view invoice/i }).click()
    await expect(page).toHaveURL(/\/invoices$/)
    await expect(page.getByText('Draft', { exact: true }).first()).toBeVisible()
    await expect(page.getByText('RM300.00').first()).toBeVisible()
  })

  test('rejecting a sent quotation', async ({ page }) => {
    await page.goto('/quotations')
    await page.getByRole('button', { name: /new quotation/i }).click()
    await page.locator('form select').first().selectOption({ label: 'Kedai Runcit Aminah' })
    await page.locator('input[type="date"]').first().fill('2026-12-31')
    await page.getByPlaceholder('Description').fill('Item')
    await page.getByPlaceholder('Qty').fill('1')
    await page.getByPlaceholder('Unit price').fill('10.00')

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/quotations') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /save as draft/i }).click(),
    ])

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/send')),
      page.getByRole('button', { name: /^send$/i }).click(),
    ])

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/reject')),
      page.getByRole('button', { name: /^reject$/i }).click(),
    ])

    await expect(page.getByText('Rejected', { exact: true })).toBeVisible()
  })
})
