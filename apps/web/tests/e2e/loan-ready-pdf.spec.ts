import { expect, test } from '@playwright/test'
import * as fs from 'node:fs/promises'
import { createAccount, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of AETS-009 §21's "loan-ready" PDF export —
 * Profit & Loss and Balance Sheet only, the two statements a bank or
 * accountant actually reviews, distinct from the raw CSV/XLSX row
 * export §18/§20 already cover.
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

    // Let the export's own request fully settle before switching tabs —
    // otherwise the next click can race the still-in-flight download
    // request, occasionally leaving the assertion below observing a
    // transient state instead of Trial Balance's own steady one.
    await page.waitForLoadState('networkidle')

    // The PDF button is not offered for reports it was never built for.
    await page.getByRole('button', { name: 'Trial Balance' }).click()
    await expect(page.getByRole('button', { name: /^pdf$/i })).toHaveCount(0)
  })
})
