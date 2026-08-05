import { expect, test } from '@playwright/test'
import { enableModule, seedVariantProduct, setConsent, setSettings, shopUrl } from '../support/shop'
import { watchVendors } from '../support/tracking'

/**
 * The ordinary path a customer takes, with JavaScript on.
 *
 * The no-JS scenario already proves the checkout completes; what this adds is
 * everything that only exists once scripts run — the variant island, the mini
 * cart, and the purchase conversion.
 */
test.describe('purchase with JavaScript', () => {
  test('the catalogue, search and cart work end to end', async ({ page }) => {
    await page.goto(shopUrl('/'))

    // Search finds a product and lands on a server-rendered result page.
    const productName = await page.locator('a[href*="/produkt/"]').first().innerText()
    await page.getByLabel('Hledat v e-shopu').fill(productName.split('\n')[0].slice(0, 8))
    await page.getByRole('button', { name: 'Hledat' }).click()

    await expect(page).toHaveURL(/\/hledani/)
    await expect(page.locator('a[href*="/produkt/"]').first()).toBeVisible()

    await page.locator('a[href*="/produkt/"]').first().click()
    await page.getByRole('button', { name: 'Přidat do košíku' }).click()

    await expect(page).toHaveURL(/\/kosik/)
    await expect(page.getByRole('heading', { name: /Košík/ })).toBeVisible()
  })

  /**
   * The variant island (wave 2.4): picking a combination updates the price
   * without a round trip. The form works without it — that is what the no-JS
   * scenario covers — so what is under test here is only the enhancement.
   */
  test('choosing a variant updates the price without a reload', async ({ page }) => {
    // Seeded by the test rather than taken from the demo data: the demo ships
    // no variants at all, and a scenario that skips itself when the fixture
    // is missing is one nobody notices has stopped running.
    const slug = seedVariantProduct()

    await page.goto(shopUrl(`/produkt/${slug}`))

    const price = page.locator('[data-variant-price]')
    await expect(price).toBeVisible()

    const before = await price.innerText()
    const url = page.url()

    // The two variants are priced differently on purpose, so a price that
    // does not move means the island did not react.
    const axis = page.locator('[data-variant-axis]').first()

    if (await axis.evaluate((el) => el.tagName) === 'SELECT') {
      await axis.selectOption({ index: 1 })
    } else {
      await page.locator('[data-variant-axis]').nth(1).check()
    }

    await expect(price).not.toHaveText(before)
    expect(page.url(), 'the variant picker reloaded the page').toBe(url)
  })

  /**
   * The other half of wave 3.3's gap: the conversion has to reach the vendor
   * once — and only once — after a purchase by a visitor who consented.
   */
  test('a purchase reports a conversion when the visitor consented', async ({ page }) => {
    enableModule('analytics')
    setSettings('analytics', { ga4_measurement_id: 'G-E2E1234567' })

    const vendors = await watchVendors(page)
    await setConsent(page, ['analytics', 'marketing'])

    await page.goto(shopUrl('/'))
    await page.locator('a[href*="/produkt/"]').first().click()
    await page.getByRole('button', { name: 'Přidat do košíku' }).click()

    await page.getByRole('link', { name: /Pokračovat k pokladně/ }).click()
    await page.locator('input[name="shipping_method_id"]').first().check()
    await page.getByRole('button', { name: 'Pokračovat' }).click()
    await page.locator('input[name="payment_method_id"]').first().check()
    await page.getByRole('button', { name: 'Pokračovat' }).click()

    await page.fill('input[name="email"]', 'e2e-js@example.com')
    await page.fill('input[name="phone"]', '777123456')
    await page.fill('input[name="name"]', 'Jan Novák')
    await page.fill('input[name="street"]', 'Testovací 1')
    await page.fill('input[name="city"]', 'Ostrava')
    await page.fill('input[name="zip"]', '70030')
    await page.check('input[name="terms"]')
    await page.getByRole('button', { name: /Objednat s povinností platby/ }).click()

    await expect(page).toHaveURL(/\/dekujeme\//)

    await expect.poll(() => vendors.hit('googletagmanager.com'), {
      message: 'the purchase conversion never reached GA4',
    }).toBe(true)
  })
})
