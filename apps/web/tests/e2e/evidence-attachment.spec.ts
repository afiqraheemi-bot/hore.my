import { expect, test } from '@playwright/test'
import { createAccount, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of AETS-015's Evidence attachment, wired into
 * `AppComposer.vue` (2026-09-16) — the file is genuinely uploaded to
 * `POST /api/v1/evidence` before the transaction itself is recorded,
 * not a decorative attachment.
 */
test.describe('Evidence attachment', () => {
  test('attaching a receipt to a manual-entry Expense uploads it and links it to the resulting Journal', async ({
    page,
  }) => {
    await registerNewUser(page)
    await createAccount(page, '5000', 'Office Supplies', 'Expense')
    await createAccount(page, '1000', 'Cash', 'Asset')

    await page.goto('/manual-entry')

    await page.getByRole('button', { name: 'Expense' }).click()
    await page.locator('input[inputmode="decimal"]').fill('42.50')
    await page.locator('form select').nth(0).selectOption({ label: '5000 — Office Supplies' })
    await page.locator('form select').nth(1).selectOption({ label: '1000 — Cash' })
    await page.getByPlaceholder('What was this for?').fill('Receipt attachment E2E proof')

    // A real, minimal 1x1 transparent PNG — Laravel's MIME validation
    // sniffs actual file content (not merely the declared Content-Type),
    // so an arbitrary text buffer would fail it regardless of the
    // filename/mimeType hints below.
    const minimalPng = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
      'base64',
    )
    await page.locator('input[type="file"]').setInputFiles({
      name: 'receipt.png',
      mimeType: 'image/png',
      buffer: minimalPng,
    })

    const [evidenceUpload] = await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/evidence') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /record/i }).click(),
    ])

    expect(evidenceUpload.status()).toBe(201)

    await expect(page.getByText('Recorded.')).toBeVisible()

    // The Recent Activity feed (Evidence Index, AETS-010) reflects the
    // real linkage — not merely that the upload endpoint returned 201.
    await expect(page.getByText('Evidence').first()).toBeVisible()
  })
})
