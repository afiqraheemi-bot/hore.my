import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { createAccount, createCustomer, registerNewUser } from './support/fixtures'

/**
 * Automated proof against HORE_MY_MASTER_CONTEXT.md §5's own "WCAG 2.2
 * AA" requirement, run with the same real axe-core engine a manual
 * audit would use — a future regression fails this suite instead of
 * shipping silently. Covers the unauthenticated Login page, every main
 * authenticated page, and dark mode.
 */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

/**
 * `#nuxt-devtools-container` is Nuxt DevTools' own dev-only overlay —
 * never present in a production build — so it is excluded from the
 * scan rather than exempted rule-by-rule; failing on its markup would
 * only ever catch a devtools regression, never this app's own.
 */
async function assertNoViolations(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(WCAG_TAGS)
    .exclude('#nuxt-devtools-container')
    .analyze()
  expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
}

test.describe('Accessibility (WCAG 2.2 AA)', () => {
  test('the Login page has no violations', async ({ page }) => {
    await page.goto('/login')
    await assertNoViolations(page)
  })

  test('the authenticated app shell has no violations across its main pages', async ({ page }) => {
    await registerNewUser(page)
    await createAccount(page, '1000', 'Cash', 'Asset')
    await createAccount(page, '4000', 'Sales', 'Revenue')
    await createCustomer(page, 'Kedai Ah Chong')

    const routes = [
      '/',
      '/dashboard',
      '/manual-entry',
      '/invoices',
      '/quotations',
      '/payments',
      '/bank-accounts',
      '/accounts',
      '/customers',
      '/reports',
      '/business-profile',
    ]

    for (const route of routes) {
      await page.goto(route)
      await assertNoViolations(page)
    }
  })

  test('a submitted Task detail page has no violations', async ({ page }) => {
    await registerNewUser(page)
    await createAccount(page, '5000', 'Office Supplies', 'Expense')
    await createAccount(page, '1000', 'Cash', 'Asset')

    await page.goto('/')
    await page.getByRole('button', { name: 'Expense' }).click()
    await page.locator('input[inputmode="decimal"]').fill('45.00')
    await page.locator('form select').nth(0).selectOption({ label: 'Office Supplies' })
    await page.locator('form select').nth(1).selectOption({ label: 'Cash' })
    await page.getByPlaceholder('What was this for?').fill('Stationery')

    await Promise.all([
      page.waitForResponse(
        (res) => res.url().endsWith('/api/v1/tasks') && res.request().method() === 'POST',
      ),
      page.getByRole('button', { name: /submit for review/i }).click(),
    ])

    await page.getByRole('link', { name: /^task /i }).first().click()
    await page.waitForURL(/\/tasks\/.+/)
    await assertNoViolations(page)
  })

  test('the Work Queue has no violations in dark mode', async ({ page }) => {
    await registerNewUser(page)
    await page.goto('/')
    await page.evaluate(() => window.localStorage.setItem('hore-theme', 'dark'))
    await page.reload()
    await expect(page.locator('html')).toHaveClass(/dark/)
    await assertNoViolations(page)
  })
})
