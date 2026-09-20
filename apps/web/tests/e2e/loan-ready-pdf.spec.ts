import { expect, test } from '@playwright/test'
import * as fs from 'node:fs/promises'
import { createAccount, createCustomer, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of AETS-009 §21's PDF export — the "loan-ready"
 * statement shape for Profit & Loss and Balance Sheet, and (as of
 * v1.9.0) the row-per-record shape for Trial Balance, General Ledger,
 * Aging Report, and Evidence Index.
 */
test.describe('Loan-ready PDF export', () => {
  test('downloading Profit & Loss and Balance Sheet as PDF produces real PDF files', async ({
    page,
  }) => {
    await registerNewUser(page)
    await createAccount(page, '1000', 'Cash', 'Asset')
    await createAccount(page, '5000', 'Office Supplies', 'Expense')
    await createAccount(page, '4000', 'Consulting Revenue', 'Revenue')

    await page.goto('/manual-entry')
    await page.getByRole('button', { name: 'Income' }).click()
    await page.locator('input[inputmode="decimal"]').fill('500.00')
    await page.locator('form select').nth(0).selectOption({ label: 'Consulting Revenue' })
    await page.locator('form select').nth(1).selectOption({ label: 'Cash' })
    await page.getByPlaceholder('What was this for?').fill('Consulting revenue')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/incomes') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /record/i }).click(),
    ])
    await page.getByText('Recorded.').waitFor()

    await page.goto('/reports')
    await page.getByRole('button', { name: 'Profit & Loss' }).click()
    await page.getByRole('button', { name: 'Run report' }).click()

    const [pnlDownload] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /^pdf$/i }).click(),
    ])
    const pnlPath = await pnlDownload.path()
    expect(pnlPath).not.toBeNull()
    const pnlBytes = await fs.readFile(pnlPath as string)
    expect(pnlBytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')

    await page.getByRole('button', { name: 'Balance Sheet' }).click()
    await page.getByRole('button', { name: 'Run report' }).click()

    const [balanceSheetDownload] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /^pdf$/i }).click(),
    ])
    const balanceSheetPath = await balanceSheetDownload.path()
    expect(balanceSheetPath).not.toBeNull()
    const balanceSheetBytes = await fs.readFile(balanceSheetPath as string)
    expect(balanceSheetBytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
  })

  test('downloading Trial Balance, General Ledger, Aging Report, and Evidence Index as PDF produces real PDF files', async ({
    page,
  }) => {
    await registerNewUser(page)
    await createAccount(page, '1000', 'Cash', 'Asset')
    await createAccount(page, '5000', 'Office Supplies', 'Expense')
    await createAccount(page, '1100', 'Accounts Receivable', 'Asset')
    await createAccount(page, '4100', 'Service Revenue', 'Revenue')
    await createCustomer(page, 'Kedai Ah Chong')

    await page.goto('/manual-entry')
    await page.getByRole('button', { name: 'Expense' }).click()
    await page.locator('input[inputmode="decimal"]').fill('45.90')
    await page.locator('form select').nth(0).selectOption({ label: 'Office Supplies' })
    await page.locator('form select').nth(1).selectOption({ label: 'Cash' })
    await page.getByPlaceholder('What was this for?').fill('Printer paper')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/expenses') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /record/i }).click(),
    ])
    await page.getByText('Recorded.').waitFor()

    await page.goto('/invoices')
    await page.getByRole('button', { name: /new invoice/i }).click()
    await page.locator('form select').first().selectOption({ label: 'Kedai Ah Chong' })
    await page.locator('input[type="date"]').first().fill('2026-09-20')
    await page.locator('form select').nth(1).selectOption({ label: 'Accounts Receivable' })
    await page.locator('form select').nth(2).selectOption({ label: 'Service Revenue' })
    await page.getByPlaceholder('Description').fill('Tudung')
    await page.getByPlaceholder('Qty').fill('5')
    await page.getByPlaceholder('Unit price').fill('20.00')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/invoices') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /save as draft/i }).click(),
    ])
    await page.locator('input[type="date"]').last().fill('2026-09-20')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().includes('/issue') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: 'Issue' }).click(),
    ])

    await page.goto('/reports')

    async function downloadAndVerifyPdf(): Promise<void> {
      await page.getByRole('button', { name: 'Run report' }).click()
      const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: /^pdf$/i }).click(),
      ])
      const downloadPath = await download.path()
      expect(downloadPath).not.toBeNull()
      const bytes = await fs.readFile(downloadPath as string)
      expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
    }

    await page.getByRole('button', { name: 'Trial Balance' }).click()
    await downloadAndVerifyPdf()

    await page.getByRole('button', { name: 'General Ledger' }).click()
    await page.getByLabel('Account').first().selectOption({ label: '1000 — Cash' })
    await downloadAndVerifyPdf()

    await page.getByRole('button', { name: 'Aging Report' }).click()
    await downloadAndVerifyPdf()

    await page.getByRole('button', { name: 'Evidence Index' }).click()
    await downloadAndVerifyPdf()
  })
})
