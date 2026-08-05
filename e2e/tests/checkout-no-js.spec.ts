import { expect, test } from '@playwright/test'
import { shopUrl } from '../support/shop'

/**
 * "The whole checkout works without JavaScript" is a binding acceptance
 * criterion (§16.3) and a binding project rule — and until now it was only
 * ever verified by checking that the server returns the right HTML, never by
 * clicking through it.
 *
 * Runs in its own Playwright project (`no-js`) with javaScriptEnabled: false.
 */
test.describe('purchase without JavaScript', () => {
  test('a customer can order from the catalogue to the thank-you page', async ({ page }) => {
    // 1. Catalogue — the products have to be in the server's first response.
    await page.goto(shopUrl('/'))
    const productLink = page.locator('a[href*="/produkt/"]').first()
    await expect(productLink).toBeVisible()
    await productLink.click()

    // 2. Product detail — price and the add-to-cart form, both server-rendered.
    await expect(page).toHaveURL(/\/produkt\//)
    const addToCart = page.getByRole('button', { name: 'Přidat do košíku' })
    await expect(addToCart).toBeVisible()
    await addToCart.click()

    // 3. Cart.
    await expect(page).toHaveURL(/\/kosik/)
    await page.getByRole('link', { name: /Pokračovat k pokladně/ }).click()

    // 4. Shipping and payment. The first option of each is enough — what is
    // under test is that the flow completes, not the matrix (which has its
    // own PHPUnit coverage).
    await expect(page).toHaveURL(/\/pokladna\/doprava/)
    await page.locator('input[name="shipping_method_id"]').first().check()
    await page.locator('input[name="payment_method_id"]').first().check()
    await page.getByRole('button', { name: 'Pokračovat' }).click()

    // 5. Details.
    await expect(page).toHaveURL(/\/pokladna\/udaje/)
    await page.fill('input[name="email"]', 'e2e@example.com')
    await page.fill('input[name="phone"]', '777123456')
    await page.fill('input[name="name"]', 'Jan Novák')
    await page.fill('input[name="street"]', 'Testovací 1')
    await page.fill('input[name="city"]', 'Ostrava')
    await page.fill('input[name="zip"]', '70030')
    await page.check('input[name="terms"]')

    await page.getByRole('button', { name: /Objednat s povinností platby/ }).click()

    // 6. Thank you — and an order number that looks like one, asserted by
    // shape rather than by a fixed value so the test does not depend on how
    // many orders the seed happened to create.
    await expect(page).toHaveURL(/\/dekujeme\//)
    await expect(page.getByText(/Děkujeme za objednávku/)).toBeVisible()
    await expect(page.locator('body')).toContainText(/\d{3,}/)
  })

  /**
   * The banner is the one piece of the page that could plausibly need JS, so
   * it gets its own check: consent has to be expressible without it.
   */
  test('consent can be given without JavaScript', async ({ page }) => {
    await page.goto(shopUrl('/'))

    await expect(page.locator('#cookie-banner')).toBeVisible()
    await page.getByRole('button', { name: 'Odmítnout vše' }).click()

    // A plain form POST and a redirect back.
    await expect(page.locator('#cookie-banner')).toBeHidden()
  })
})
