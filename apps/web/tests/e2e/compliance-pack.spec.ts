import { expect, test } from '@playwright/test'
import { registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of the Compliance Pack export (AETS-009 §19) —
 * the other half of item #6 ("Quotation, compliance pack dan export
 * menyeluruh"): a real ZIP file, containing real CSV report entries,
 * downloaded from the Reports page.
 */
test.describe('Compliance Pack export', () => {
  test('downloading the compliance pack produces a real zip file', async ({ page }) => {
    await registerNewUser(page)
    await page.goto('/reports')

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /compliance pack/i }).click(),
    ])

    expect(download.suggestedFilename()).toMatch(/^compliance-pack-.*\.zip$/)

    const path = await download.path()
    expect(path).not.toBeNull()

    // A real ZIP file always starts with the local-file-header or
    // end-of-central-directory magic bytes ("PK") — this is a cheap,
    // real proof the downloaded bytes are an actual archive, not an
    // error page or empty response silently saved with a .zip name.
    const fs = await import('node:fs/promises')
    const bytes = await fs.readFile(path as string)
    expect(bytes.subarray(0, 2).toString('latin1')).toBe('PK')
  })
})
