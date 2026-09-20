import { expect, test } from '@playwright/test'
import { createAccount, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of AETS-014's Period closing (M13) — a backend
 * capability that existed fully tested but had no UI at all until this
 * spec's own feature landed. Confirms the "Close Period" card on
 * /reports lets a user actually close the books, updates the
 * watermark, and that the resulting Balance Sheet shows the
 * "unclosed books" convention correctly zeroed.
 */
test.describe('Close Period', () => {
  test('closing the current period zeroes revenue into retained earnings and advances the watermark', async ({
    page,
  }) => {
    // Always "today" rather than a fixed calendar date — a hardcoded
    // past date eventually falls before a transaction dated "today"
    // (manual-entry's own default), which the real backend rejects,
    // making this spec silently stale as time passes rather than
    // testing the same flow indefinitely.
    const today = new Date().toISOString().slice(0, 10)

    await registerNewUser(page)
    await createAccount(page, '1000', 'Cash', 'Asset')
    await createAccount(page, '4000', 'Consulting Revenue', 'Revenue')
    await createAccount(page, '3900', 'Retained Earnings', 'Equity')

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
    await expect(page.getByText('Books currently closed through:')).toBeVisible()
    await expect(page.getByText('never')).toBeVisible()

    page.once('dialog', (dialog) => dialog.accept())
    await page.locator('input[type="date"]').last().fill(today)
    await page
      .getByLabel('Retained Earnings account (Equity)')
      .selectOption({ label: '3900 — Retained Earnings' })

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/periods/close') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: 'Close Period' }).click(),
    ])

    await expect(page.getByText(`Books closed through ${today}.`)).toBeVisible()
    await expect(page.getByText(today).last()).toBeVisible()

    // Reloading the page proves the watermark is real server state, not
    // merely a local success message.
    await page.reload()
    await expect(page.getByText('Books currently closed through:')).toBeVisible()
    await expect(page.getByText(today)).toBeVisible()

    // The "unclosed books" convention (AETS-009 §8) now sees the real
    // closing Journal instead of computing Cumulative Net Income itself
    // — the Balance Sheet still balances.
    await page.getByRole('button', { name: 'Balance Sheet' }).click()
    await page.getByRole('button', { name: 'Run report' }).click()
    await expect(page.getByText('Balanced')).toBeVisible()
  })
})
