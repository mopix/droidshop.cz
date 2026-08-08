import { expect, test } from '@playwright/test'
import { setShopSettings, shopUrl } from '../support/shop'

/**
 * A shop behind a password, in a real browser (wave 3.6).
 *
 * The PHPUnit suite already covers the middleware. What this adds is the one
 * thing a request-level test cannot fully stand in for: the shop is browsed
 * first, so anything a warm page cache kept is in play, and the catalogue must
 * still not come back after the lock goes on.
 */
test.describe('a shop behind a password', () => {
  test.afterEach(() => {
    setShopSettings({ locked: false })
  })

  test('a locked shop answers with the form, not the catalogue', async ({ page }) => {
    // Browse first, so whatever the page cache stores is stored.
    await page.goto(shopUrl('/'))
    await expect(page.locator('body')).toContainText('Demo')

    setShopSettings({ locked: true, lock_password: 'tajne-heslo' })

    // Twice: the second load is the one a cached page would answer.
    for (const attempt of [1, 2]) {
      const response = await page.goto(shopUrl('/'))

      expect(response?.status(), `pokus ${attempt}`).toBe(403)
      await expect(page.getByLabel('Heslo')).toBeVisible()
    }

    await page.getByLabel('Heslo').fill('spatne')
    await page.getByRole('button', { name: 'Vstoupit' }).click()
    await expect(page.getByText('Heslo není správné.')).toBeVisible()

    await page.getByLabel('Heslo').fill('tajne-heslo')
    await page.getByRole('button', { name: 'Vstoupit' }).click()

    // Unlocked, and it stays unlocked on the next page.
    await expect(page.getByRole('link', { name: 'Košík' })).toBeVisible()
    await page.goto(shopUrl('/kosik'))
    await expect(page.locator('body')).not.toContainText('Vstoupit')
  })
})
