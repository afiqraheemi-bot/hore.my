import { expect, test } from '@playwright/test'
import { createAccount, createTaskInNeedsReview, registerNewUser } from './support/fixtures'

/**
 * Real-browser proof of the Work Queue / Task Detail / Human
 * Confirmation flow (ADR-0009, WTS-001) — the committed counterpart
 * to the manual Playwright verification this module's own commit
 * history previously described but never checked in (a real,
 * previously-flagged gap: see `tests/e2e/README.md`).
 *
 * Every test here reaches `NeedsReview` via
 * {@see createTaskInNeedsReview} — deferring the Account decision,
 * then completing it — since a fully-filled composer now posts
 * directly and never creates a Task at all (2026-09-21,
 * Founder-directed follow-up to UX-01; see AppComposer.vue's own
 * docblock). That is currently the only door into this pipeline; a
 * future AI-produced Proposal will be the second one.
 */
test.describe('Work Queue and Human Confirmation', () => {
  test('the authenticated landing route opens Work Queue, and /manual-entry resolves to the same page', async ({
    page,
  }) => {
    await page.goto('/')
    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByRole('heading', { name: 'Your work' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Recent activity' })).toBeVisible()

    // Manual Entry no longer has its own sidebar entry or distinct
    // posture — it's a historical alias so old links still resolve.
    await page.goto('/manual-entry')
    await expect(page).toHaveURL(/\/manual-entry$/)
    await expect(page.getByRole('heading', { name: 'Your work' })).toBeVisible()
  })

  test.beforeEach(async ({ page }) => {
    await registerNewUser(page)
    await createAccount(page, '5000', 'Office Supplies', 'Expense')
    await createAccount(page, '1000', 'Cash', 'Asset')
  })

  test('deferring then completing a Task lands it in NeedsReview with its Proposal detail', async ({
    page,
  }) => {
    await createTaskInNeedsReview(page, '88.50', 'Office Supplies', 'Cash', 'E2E: office supplies')

    await expect(page.getByText('NeedsReview', { exact: true })).toBeVisible()
    await expect(page.getByText('RM88.50')).toBeVisible()
    await expect(page.getByText('E2E: office supplies')).toBeVisible()
    await expect(page.getByRole('button', { name: /confirm and post/i })).toBeVisible()

    await page.goto('/tasks')
    await expect(page.getByText('NeedsReview')).toBeVisible()
    await page.getByRole('tab', { name: /in progress/i }).click()
    await expect(page.getByText('Nothing in progress')).toBeVisible()
    await page.getByRole('tab', { name: /all tasks/i }).click()
    await expect(page.getByText('NeedsReview')).toBeVisible()
  })

  test("a Task submitted with a receipt shows a working 'View evidence' link on its own detail page", async ({
    page,
  }) => {
    await page.goto('/')
    await page.locator('input[inputmode="decimal"]').fill('42.50')
    await page.getByPlaceholder('What was this for?').fill('E2E: with receipt')
    await page.getByText('Not sure which accounts yet? Decide later.').click()

    const minimalPng = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
      'base64',
    )
    await page.locator('input[type="file"]').setInputFiles({
      name: 'receipt.png',
      mimeType: 'image/png',
      buffer: minimalPng,
    })

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/tasks') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /save for later/i }).click(),
    ])

    await page.locator('a[href^="/tasks/"]').first().click()
    await expect(page.getByRole('heading', { name: 'Needs more information' })).toBeVisible()
    await page.locator('form select').nth(0).selectOption({ label: 'Office Supplies' })
    await page.locator('form select').nth(1).selectOption({ label: 'Cash' })
    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/provide-information')),
      page.getByRole('button', { name: /submit for review/i }).click(),
    ])

    await expect(page.getByText('NeedsReview', { exact: true })).toBeVisible()

    const evidenceLink = page.getByRole('link', { name: /view evidence/i })
    await expect(evidenceLink).toBeVisible()
    const evidenceHref = await evidenceLink.getAttribute('href')
    expect(evidenceHref).toMatch(/\/api\/v1\/evidence\/.+/)

    // Listening at the BrowserContext level (rather than on a `page`
    // handle obtained after the click) avoids racing the new tab's
    // own near-instant response for a 67-byte PNG.
    const [evidenceResponse] = await Promise.all([
      page.context().waitForEvent('response', (res) => res.url() === evidenceHref),
      evidenceLink.click(),
    ])
    expect(evidenceResponse.status()).toBe(200)
    expect(evidenceResponse.headers()['content-type']).toBe('image/png')
  })

  test('confirming a Task posts a Journal and shows the full transition history', async ({
    page,
  }) => {
    await createTaskInNeedsReview(page, '88.50', 'Office Supplies', 'Cash', 'E2E: confirm flow')

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/approve')),
      page.getByRole('button', { name: /confirm and post/i }).click(),
    ])

    await expect(page.getByText('Completed', { exact: true })).toBeVisible()
    await expect(page.getByText('Recorded to your books.')).toBeVisible()
    await expect(page.getByText('Received → Processing')).toBeVisible()
    await expect(page.getByText('NeedsReview → Approved')).toBeVisible()
    await expect(page.getByText('Executing → Completed')).toBeVisible()
  })

  test('rejecting a Task requires a reason and moves it to Rejected', async ({ page }) => {
    await createTaskInNeedsReview(page, '88.50', 'Office Supplies', 'Cash', 'E2E: reject flow')

    await page.getByRole('button', { name: /^reject$/i }).click()
    await page.getByPlaceholder('Wrong account, duplicate, etc.').fill('Wrong account chosen.')

    await Promise.all([
      page.waitForResponse((res) => res.url().includes('/reject')),
      page.getByRole('button', { name: /^reject$/i }).click(),
    ])

    await expect(page.getByText('Rejected', { exact: true })).toBeVisible()
  })

  test("a Task submitted under one tenant never appears in another tenant's Work Queue", async ({
    page,
  }) => {
    await createTaskInNeedsReview(page, '88.50', 'Office Supplies', 'Cash', 'E2E: tenant A only')
    await expect(page.getByText('NeedsReview', { exact: true })).toBeVisible()

    await page.getByRole('button', { name: /log out/i }).click()
    await page.waitForURL('**/login')
    await registerNewUser(page)

    await page.goto('/tasks')
    await expect(page.getByText('Nothing waiting on you')).toBeVisible()
    await expect(page.getByText('E2E: tenant A only')).not.toBeVisible()
  })

  /**
   * IDOR (insecure direct object reference) proof: knowing a Task's
   * id (visible in tenant A's own URL bar, trivially guessable from
   * one's own Tasks, or leaked via a shared link) must not let a
   * *different, authenticated* Tenant load it by navigating straight
   * to its URL — the Work-Queue-listing test above only proves it is
   * not *listed* for another Tenant, which is a weaker property than
   * this: a listing omission alone would not stop a direct URL visit
   * from working if the `/tasks/{id}` route itself were not
   * independently tenant-checked.
   */
  test('a Task is not directly loadable by URL from a different, authenticated tenant', async ({
    page,
  }) => {
    await createTaskInNeedsReview(
      page,
      '88.50',
      'Office Supplies',
      'Cash',
      'E2E: tenant A direct object',
    )
    // NuxtLink navigation is client-side (Vue Router) — page.url() read
    // immediately after click() can race the URL actually updating.
    await page.waitForURL(/\/tasks\/[^/]+$/)
    const tenantATaskUrl = page.url()

    await page.getByRole('button', { name: /log out/i }).click()
    await page.waitForURL('**/login')
    await registerNewUser(page)

    await page.goto(tenantATaskUrl)

    await expect(page.getByText('Failed to load this Task.')).toBeVisible()
    await expect(page.getByText('E2E: tenant A direct object')).not.toBeVisible()
    await expect(page.getByText('RM88.50')).not.toBeVisible()
  })
})
