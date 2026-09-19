import { expect, test } from '@playwright/test'
import * as fs from 'node:fs/promises'
import * as path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createAccount, createCustomer, registerNewUser } from './support/fixtures'

const currentDir = path.dirname(fileURLToPath(import.meta.url))

/**
 * Real-browser proof of AETS-017 (Invoice/Quotation PDF export) and
 * AETS-009 §20 / AETS-008 §5.1 (XLSX export/import) — the remaining
 * half of "laksanakan Import & Eksport PDF/XLSX" not already covered
 * by `compliance-pack.spec.ts` and `quotations.spec.ts`.
 */
test.describe('XLSX and PDF export/import', () => {
  test('exporting the Trial Balance as XLSX produces a real workbook', async ({ page }) => {
    await registerNewUser(page)
    await page.goto('/reports')

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /^excel$/i }).click(),
    ])

    expect(download.suggestedFilename()).toMatch(/^trial-balance\.xlsx$/)

    const downloadPath = await download.path()
    expect(downloadPath).not.toBeNull()

    // A real XLSX file is a ZIP container — proven the same cheap way
    // `compliance-pack.spec.ts` proves its ZIP: the "PK" magic bytes,
    // not merely that a response with an .xlsx name arrived.
    const bytes = await fs.readFile(downloadPath as string)
    expect(bytes.subarray(0, 2).toString('latin1')).toBe('PK')
  })

  test('downloading an Invoice PDF produces a real PDF file', async ({ page }) => {
    await registerNewUser(page)
    await createCustomer(page, 'Kedai Runcit Aminah')
    await createAccount(page, '1100', 'Accounts Receivable', 'Asset')
    await createAccount(page, '4100', 'Service Revenue', 'Revenue')

    await page.goto('/invoices')
    await page.getByRole('button', { name: /new invoice/i }).click()
    await page.locator('form select').first().selectOption({ label: 'Kedai Runcit Aminah' })
    await page.locator('input[type="date"]').first().fill('2026-12-31')
    await page.locator('form select').nth(1).selectOption({ label: 'Accounts Receivable' })
    await page.locator('form select').nth(2).selectOption({ label: 'Service Revenue' })
    await page.getByPlaceholder('Description').fill('Consulting')
    await page.getByPlaceholder('Qty').fill('1')
    await page.getByPlaceholder('Unit price').fill('250.00')

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/invoices') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /save as draft/i }).click(),
    ])

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('link', { name: /pdf/i }).click(),
    ])

    const downloadPath = await download.path()
    expect(downloadPath).not.toBeNull()

    // A real PDF always opens with the "%PDF-" magic bytes.
    const bytes = await fs.readFile(downloadPath as string)
    expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
  })

  test('downloading a Quotation PDF produces a real PDF file', async ({ page }) => {
    await registerNewUser(page)
    await createCustomer(page, 'Kedai Runcit Aminah')

    await page.goto('/quotations')
    await page.getByRole('button', { name: /new quotation/i }).click()
    await page.locator('form select').first().selectOption({ label: 'Kedai Runcit Aminah' })
    await page.locator('input[type="date"]').first().fill('2026-12-31')
    await page.getByPlaceholder('Description').fill('Consulting')
    await page.getByPlaceholder('Qty').fill('1')
    await page.getByPlaceholder('Unit price').fill('99.00')

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/quotations') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /save as draft/i }).click(),
    ])

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('link', { name: /pdf/i }).click(),
    ])

    const downloadPath = await download.path()
    expect(downloadPath).not.toBeNull()

    const bytes = await fs.readFile(downloadPath as string)
    expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
  })

  test('downloading a Payment receipt produces a real PDF file', async ({ page }) => {
    await registerNewUser(page)
    await createCustomer(page, 'Kedai Runcit Aminah')
    await createAccount(page, '1000', 'Bank', 'Asset')
    await createAccount(page, '1100', 'Accounts Receivable', 'Asset')

    await page.goto('/payments')
    await page.getByRole('button', { name: /record payment/i }).click()
    await page.locator('form select').nth(0).selectOption({ label: 'Kedai Runcit Aminah' })
    await page.getByPlaceholder('300.00').fill('150.00')
    await page.locator('input[type="date"]').fill('2026-09-17')
    await page.locator('form select').nth(1).selectOption({ label: 'Bank' })
    await page.locator('form select').nth(2).selectOption({ label: 'Accounts Receivable' })

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/payments') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /^record$/i }).click(),
    ])

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('link', { name: /receipt/i }).click(),
    ])

    const downloadPath = await download.path()
    expect(downloadPath).not.toBeNull()

    const bytes = await fs.readFile(downloadPath as string)
    expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-')
  })

  test('importing an XLSX bank statement records real transactions', async ({ page }) => {
    await registerNewUser(page)
    await createAccount(page, '1000', 'Cash', 'Asset')

    await page.goto('/bank-accounts')
    await page.getByRole('button', { name: /register bank account/i }).click()
    await page.locator('form select').first().selectOption({ label: 'Cash' })
    await page.getByPlaceholder('Maybank').fill('Maybank')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/bank-accounts') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /^register$/i }).click(),
    ])

    await page.getByText('Maybank').click()

    // A genuine XLSX workbook (built via the API's own PhpSpreadsheet,
    // AETS-008 §5.1) with the exact "date,description,amount,direction,
    // balance,reference" header — Laravel's MIME sniffing rejects an
    // arbitrary buffer regardless of the declared filename/mimeType.
    const fixturePath = path.join(currentDir, 'support/fixtures-data/bank-statement.xlsx')
    const fixtureBytes = await fs.readFile(fixturePath)

    await page.locator('input[type="file"]').setInputFiles({
      name: 'statement.xlsx',
      mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      buffer: fixtureBytes,
    })

    const [importResponse] = await Promise.all([
      page.waitForResponse(
        (res) => res.url().includes('/import') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /^import$/i }).click(),
    ])

    expect(importResponse.status()).toBe(201)
    await expect(page.getByText(/imported 1 new row/i)).toBeVisible()
    await expect(page.getByText('E2E XLSX import test')).toBeVisible()
  })

  test('importing a Maybank PDF bank statement records real transactions', async ({ page }) => {
    await registerNewUser(page)
    await createAccount(page, '1000', 'Cash', 'Asset')

    await page.goto('/bank-accounts')
    await page.getByRole('button', { name: /register bank account/i }).click()
    await page.locator('form select').first().selectOption({ label: 'Cash' })
    await page.getByPlaceholder('Maybank').fill('Maybank')
    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/bank-accounts') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /^register$/i }).click(),
    ])

    await page.getByText('Maybank').click()

    // A synthetic PDF (AETS-008 §12.10) built to exercise Maybank's own
    // real statement layout — never real bank data. Generated once via
    // dompdf and round-trip-verified against
    // MaybankPdfBankStatementParser before being committed as a fixture.
    const fixturePath = path.join(currentDir, 'support/fixtures-data/bank-statement-maybank.pdf')
    const fixtureBytes = await fs.readFile(fixturePath)

    await page.locator('input[type="file"]').setInputFiles({
      name: 'statement.pdf',
      mimeType: 'application/pdf',
      buffer: fixtureBytes,
    })

    const [importResponse] = await Promise.all([
      page.waitForResponse(
        (res) => res.url().includes('/import') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /^import$/i }).click(),
    ])

    expect(importResponse.status()).toBe(201)
    await expect(page.getByText(/imported 1 new row/i)).toBeVisible()
    await expect(page.getByText('E2E Maybank PDF import test')).toBeVisible()
  })
})
