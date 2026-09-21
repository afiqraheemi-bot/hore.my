import { expect, test } from '@playwright/test'
import { registerNewUser } from './support/fixtures'

/**
 * Real-browser proof that the composer's transaction-type tabs — wider
 * than a phone viewport, horizontally scrollable with no native
 * scrollbar — hint that more content exists off-screen, and that the
 * hidden tabs are actually reachable. Without this, a phone user has
 * no visual cue that "Capital contribution" and "Owner drawing" exist
 * at all.
 *
 * The Work Queue's own filter-tab fade this file previously also
 * covered no longer applies — the four-tab filter system it tested
 * was replaced by one unified, chronological feed (Founder-directed
 * simplification, 2026-09-21) with nothing left to horizontally
 * scroll.
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
})
