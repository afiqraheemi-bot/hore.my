import { expect, test } from '@playwright/test'
import { createAccount, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof that receiving and repaying a loan is a guided,
 * named quick-entry type in the composer — not something a user must
 * discover can be done via the generic "Transfer" type between an
 * arbitrary Asset and Liability account. Both post through the exact
 * same `/api/v1/transfers` endpoint Transfer itself uses (a loan
 * received/repaid *is* a Transfer, never a distinct Posting Command),
 * so no new backend behavior is being proven here beyond the composer
 * offering it as its own labeled entry point.
 */
test.describe('Loan received and repayment', () => {
  test('recording a loan received and a loan repayment from the composer', async ({ page }) => {
    await registerNewUser(page)
    await createAccount(page, '2200', 'Bank Loan', 'Liability')
    await createAccount(page, '1000', 'Cash', 'Asset')

    await page.goto('/manual-entry')

    await page.getByRole('button', { name: 'Loan received' }).click()
    await page.locator('input[inputmode="decimal"]').fill('5000.00')
    await page.locator('form select').nth(0).selectOption({ label: 'Bank Loan' })
    await page.locator('form select').nth(1).selectOption({ label: 'Cash' })
    await page.getByPlaceholder('What was this for?').fill('Loan from Maybank')

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/transfers') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /record/i }).click(),
    ])
    await expect(page.getByText('Recorded.')).toBeVisible()

    await page.getByRole('button', { name: 'Loan repayment' }).click()
    await page.locator('input[inputmode="decimal"]').fill('500.00')
    await page.locator('form select').nth(0).selectOption({ label: 'Cash' })
    await page.locator('form select').nth(1).selectOption({ label: 'Bank Loan' })
    await page.getByPlaceholder('What was this for?').fill('Monthly installment')

    const [repaymentResponse] = await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/transfers') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /record/i }).click(),
    ])
    expect(repaymentResponse.status()).toBe(201)
    await expect(page.getByText('Recorded.')).toBeVisible()

    // The loan account's balance reflects both movements: 5000 in,
    // 500 repaid — a real ledger effect, not merely two 201s. Fetched
    // via the page's own browser `fetch` (not Playwright's Node-side
    // `page.request`) so the Sanctum SPA session cookie is sent
    // exactly as it is for every other real request this app makes.
    const accountsBody = await page.evaluate(async () => {
      const res = await fetch('http://localhost:8000/api/v1/accounts', { credentials: 'include' })
      return res.json()
    })
    const loanAccountId = accountsBody.data.find(
      (a: { account_name: string }) => a.account_name === 'Bank Loan',
    ).id

    const ledgerBody = await page.evaluate(async (accountId: string) => {
      const res = await fetch(
        `http://localhost:8000/api/v1/reports/general-ledger?account_id=${accountId}&period_start=2026-01-01&period_end=2026-12-31`,
        { credentials: 'include' },
      )
      return res.json()
    }, loanAccountId)
    expect(ledgerBody.closing_balance.amount).toBe('4500.00')
  })
})
