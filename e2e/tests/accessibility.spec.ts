import { expect, test } from '@playwright/test'
import { seedVariantProduct, shopUrl } from '../support/shop'
import { auditPage } from '../support/a11y'

/**
 * WCAG 2.2 AA is a legal obligation here (EAA), and until now it was checked
 * only by an agent during review — irregularly and without a record.
 *
 * axe catches roughly a third of real accessibility problems. It does not
 * replace the manual check or the a11y-checker agent; what it does is catch
 * regressions in the third it knows about, every time, for free.
 */

test.describe('accessibility', () => {
  test('homepage', async ({ page }) => {
    await page.goto(shopUrl('/'))
    await auditPage(page, 'homepage')
  })

  /**
   * With the banner on screen, which is how a first-time visitor sees every
   * page — and the banner is the newest markup in the project.
   */
  test('homepage with the cookie banner', async ({ page }) => {
    await page.goto(shopUrl('/'))
    await expect(page.locator('#cookie-banner')).toBeVisible()
    await auditPage(page, 'homepage + cookie banner')
  })

  test('product detail', async ({ page }) => {
    await page.goto(shopUrl('/'))
    await page.locator('a[href*="/produkt/"]').first().click()
    await auditPage(page, 'product detail')
  })

  test('product detail with variants', async ({ page }) => {
    const slug = seedVariantProduct()
    await page.goto(shopUrl(`/produkt/${slug}`))
    await auditPage(page, 'product detail with variants')
  })

  test('cart', async ({ page }) => {
    await page.goto(shopUrl('/'))
    await page.locator('a[href*="/produkt/"]').first().click()
    await page.getByRole('button', { name: 'Přidat do košíku' }).click()
    await expect(page).toHaveURL(/\/kosik/)
    await auditPage(page, 'cart')
  })

  test('checkout', async ({ page }) => {
    await page.goto(shopUrl('/'))
    await page.locator('a[href*="/produkt/"]').first().click()
    await page.getByRole('button', { name: 'Přidat do košíku' }).click()
    await page.getByRole('link', { name: /Pokračovat k pokladně/ }).click()
    await auditPage(page, 'checkout — shipping and payment')
  })

  test('cookie settings', async ({ page }) => {
    await page.goto(shopUrl('/souhlas-cookies'))
    await auditPage(page, 'cookie settings')
  })
})
