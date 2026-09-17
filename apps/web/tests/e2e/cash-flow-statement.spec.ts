import { expect, test } from '@playwright/test'
import * as fs from 'node:fs/promises'
import { createAccount, registerBankAccount, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of AETS-009 §22's Cash Flow Statement — resolving
 * the long-deferred SRS RPT-003 report. Proves the Cash Flow tab
 * renders a real posted Income as an Operating inflow, and that all
 * three export formats (CSV/XLSX/PDF) work from the live stack, not
 * just the backend test suite.
 */
test.describe('Cash Flow Statement', () => {
  test('recording income shows up as an operating cash inflow and exports correctly', async ({
    page,
  }) => {
    await registerNewUser(page)
    await createAccount(page, '1000', 'Cash', 'Asset')
    await createAccount(page, '4000', 'Consulting Revenue', 'Revenue')
    await registerBankAccount(page, 'Cash', 'Maybank')

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
    await page.getByRole('button', { name: 'Cash Flow' }).click()
    await page.getByRole('button', { name: 'Run report' }).click()

    await expect(page.getByText('Operating Activities')).toBeVisible()
    await expect(page.getByText('Consulting Revenue')).toBeVisible()
    await expect(page.getByText('Cash at period end')).toBeVisible()
    await expect(page.getByText('RM 500.00').first()).toBeVisible()

    const [csvDownload] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /^csv$/i }).click(),
    ])
    const csvPath = await csvDownload.path()
    expect(csvPath).not.toBeNull()
    const csvContent = await fs.readFile(csvPath as string, 'utf-8')
    expect(csvContent).toContain('Operating')

    const [xlsxDownload] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /^excel$/i }).click(),
    ])
    expect(await xlsxDownload.path()).not.toBeNull()

    const [pdfDownload] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /^pdf$/i }).click(),
    ])
    const pdfPath = await pdfDownload.path()
    expect(pdfPath).not.toBeNull()
    const pdfBytes = await fs.readFile(pdfPath as string)
    expect(pdfBytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
  })
})
