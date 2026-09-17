import { expect, test } from '@playwright/test'
import { registerNewUser } from './support/fixtures'

/**
 * Real-browser proof that the composer's transaction-type tabs and
 * the Work Queue's filter tabs — both wider than a phone viewport,
 * both horizontally scrollable with no native scrollbar — hint that
 * more content exists off-screen, and that the hidden tabs are
 * actually reachable. Without this, a phone user has no visual cue
 * that "Capital contribution" and "Owner drawing" exist at all.
 */
test.describe('Mobile horizontal scroll fade', () => {
  test.use({ viewport: { width: 390, height: 844 } })

  test('the composer type tabs show a fade hint and every type is reachable by scrolling', async ({
    page,
  }) => {
    await registerNewUser(page)
    await page.goto('/')
    await page.waitForLoadState('networkidle')

    const typeTabsScroll = page.locator('div.overflow-x-auto').first()
    const rightFade = typeTabsScroll.locator('xpath=..').locator('div.pointer-events-none').last()

    await expect(rightFade).toBeVisible()
    await expect(page.getByRole('button', { name: 'Owner drawing' })).not.toBeInViewport()

    await typeTabsScroll.evaluate((el) => {
      el.scrollLeft = el.scrollWidth
    })

    await expect(page.getByRole('button', { name: 'Owner drawing' })).toBeInViewport()
    await page.getByRole('button', { name: 'Owner drawing' }).click()
    await expect(page.getByRole('button', { name: 'Owner drawing' })).toHaveClass(/bg-accent/)
  })

  test('the Work Queue filter tabs show a fade hint and every filter is reachable', async ({
    page,
  }) => {
    await registerNewUser(page)
    await page.goto('/')
    await page.waitForLoadState('networkidle')

    const filterScroll = page.getByRole('tablist', { name: 'Filter work queue' })
    await expect(page.getByRole('tab', { name: /all tasks/i })).not.toBeInViewport()

    await filterScroll.evaluate((el) => {
      el.scrollLeft = el.scrollWidth
    })

    const allTasksTab = page.getByRole('tab', { name: /all tasks/i })
    await expect(allTasksTab).toBeInViewport()
    await allTasksTab.click()
    await expect(allTasksTab).toHaveAttribute('aria-selected', 'true')
  })
})
