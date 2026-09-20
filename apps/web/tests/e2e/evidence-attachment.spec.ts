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
    await page.locator('form select').nth(0).selectOption({ label: 'Office Supplies' })
    await page.locator('form select').nth(1).selectOption({ label: 'Cash' })
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
    const evidenceLink = page.getByRole('link', { name: /evidence/i }).first()
    await expect(evidenceLink).toBeVisible()

    // The amount the Founder specifically asked for (2026-09-21) — this
    // feed previously showed description/date/evidence only, never how
    // much the transaction was for.
    await expect(page.getByText('RM42.50', { exact: true })).toBeVisible()

    // The badge is a real link to the originally-uploaded file, not
    // just a decorative "attached" indicator — clicking it (`target=
    // "_blank"`, opening a second tab in the same authenticated
    // context) must return the actual receipt, not a 404. Listening
    // at the BrowserContext level (rather than on a `page` handle
    // obtained after the click) avoids racing the new tab's own
    // near-instant response for a 67-byte PNG.
    const evidenceHref = await evidenceLink.getAttribute('href')
    expect(evidenceHref).toMatch(/\/api\/v1\/evidence\/.+/)
    const [evidenceResponse] = await Promise.all([
      page.context().waitForEvent('response', (res) => res.url() === evidenceHref),
      evidenceLink.click(),
    ])
    expect(evidenceResponse.status()).toBe(200)
    expect(evidenceResponse.headers()['content-type']).toBe('image/png')

    // Same underlying data, a different renderer (`ReportViewer.vue`) —
    // both the Evidence Index and General Ledger report tabs surface
    // the identical `evidence_references` the API already returns, so
    // this must work there too, not only on Recent Activity.
    await page.goto('/reports')
    await page.getByRole('button', { name: 'Evidence Index' }).click()
    await page.getByRole('button', { name: 'Run report' }).click()
    const reportEvidenceLink = page.getByRole('link', { name: /1 attached/i })
    await expect(reportEvidenceLink).toBeVisible()
    expect(await reportEvidenceLink.getAttribute('href')).toMatch(/\/api\/v1\/evidence\/.+/)
    await expect(page.getByText('RM 42.50').first()).toBeVisible()

    await page.getByRole('button', { name: 'General Ledger' }).click()
    await page.getByRole('button', { name: 'Run report' }).click()
    const glEvidenceLink = page.locator('table').getByRole('link').first()
    await expect(glEvidenceLink).toBeVisible()
    expect(await glEvidenceLink.getAttribute('href')).toMatch(/\/api\/v1\/evidence\/.+/)
  })
})
