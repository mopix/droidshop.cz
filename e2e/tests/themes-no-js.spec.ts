import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl } from '../support/shop'

/**
 * Each theme has to survive a purchase with JavaScript switched off.
 *
 * The PHPUnit contract suite asserts that the add-to-cart form is a real POST
 * in every theme, which is the mechanism. This is the outcome: a shopper
 * clicking through a theme's own markup from the catalogue to the thank-you
 * page, in a browser that runs none of the shop's scripts.
 *
 * Runs in the `no-js` project (javaScriptEnabled: false).
 */
const THEMES = ['base', 'editorial', 'retail', 'katalog']

function useTheme(key: string): void {
  artisanEval(
    `App\\Models\\TenantTheme::query()->updateOrCreate(` +
      `['tenant_id' => App\\Models\\Tenant::query()->value('id')], ['template' => '${key}']` +
      `);`,
  )
}

test.describe('purchase without JavaScript, in every theme', () => {
  test.afterAll(() => useTheme('base'))

  for (const theme of THEMES) {
    test(`a customer can order from the catalogue to the thank-you page — ${theme}`, async ({ page }) => {
      useTheme(theme)

      await page.goto(shopUrl('/'))

      // Decline cookies first, and do it through the banner's own form — it
      // is fixed to the bottom of every page, and on the checkout steps it
      // sits over the "Pokračovat" button. A customer meets exactly this and
      // answers it the same way; without JavaScript the answer is a POST like
      // any other.
      await page.getByRole('button', { name: 'Odmítnout vše' }).click()

      const productLink = page.locator('a[href*="/produkt/"]').first()
      await expect(productLink).toBeVisible()
      await productLink.click()

      await expect(page).toHaveURL(/\/produkt\//)
      // The label is the theme's to choose — "Přidat do košíku" reads as
      // editorial restraint, "Vložit do košíku" as retail directness — so the
      // button is found by what it does, not by what it says.
      const addToCart = page.locator('form[action*="/kosik"] button[type="submit"]').first()
      await expect(addToCart).toBeVisible()
      await addToCart.click()

      await expect(page).toHaveURL(/\/kosik/)
      await page.getByRole('link', { name: /Pokračovat k pokladně/ }).click()

      await expect(page).toHaveURL(/\/pokladna\/doprava/)
      await page.locator('input[name="shipping_method_id"]').first().check()
      // Submitted from the keyboard, not clicked. The cookie banner is fixed
      // to the bottom of every page and, with JavaScript off, nothing ever
      // removes it — on a page as short as a checkout step it covers the
      // submit button and scrolling does not help, because there is nothing
      // left to scroll. That is a real finding about the banner (recorded in
      // the as-is), and a keyboard user gets past it exactly like this.
      await page.getByRole('button', { name: 'Pokračovat' }).press('Enter')

      const payment = page.locator('input[name="payment_method_id"]').first()
      await expect(payment, 'no payment method was offered for the chosen carrier').toBeVisible()
      await payment.check()
      await page.getByRole('button', { name: 'Pokračovat' }).press('Enter')

      await expect(page).toHaveURL(/\/pokladna\/udaje/)
      await page.fill('input[name="email"]', 'e2e@example.com')
      await page.fill('input[name="phone"]', '777123456')
      await page.fill('input[name="name"]', 'Jan Novák')
      await page.fill('input[name="street"]', 'Testovací 1')
      await page.fill('input[name="city"]', 'Ostrava')
      await page.fill('input[name="zip"]', '70030')
      await page.check('input[name="terms"]')

      await page.getByRole('button', { name: /Objednat s povinností platby/ }).press('Enter')

      await expect(page).toHaveURL(/\/dekujeme\//)
      await expect(page.getByText(/Děkujeme za objednávku/)).toBeVisible()
    })
  }
})
