import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl } from '../support/shop'

/**
 * What a shop tells its customers about VAT (wave 3.7).
 *
 * The PHPUnit suite asserts the markup. This walks the page as a shopper
 * does, and — more usefully — flips the registration back and forth on the
 * same shop, which is the transition where a half-applied change shows up.
 */
function setVatPayer(payer: boolean): void {
  artisanEval(`
    $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
    $t->update(['vat_payer' => ${payer ? 'true' : 'false'}]);
  `)
}

test.describe('VAT on the storefront', () => {
  test.afterEach(() => {
    // The demo is a VAT payer; the rest of the suite expects it back.
    setVatPayer(true)
  })

  test('a registered shop shows the VAT lines and an unregistered one does not', async ({
    page,
  }) => {
    await page.goto(shopUrl('/'))
    const productLink = page.locator('a[href^="/produkt/"]').first()
    const href = await productLink.getAttribute('href')

    setVatPayer(true)
    await page.goto(shopUrl(href!))
    await expect(page.getByText('s DPH')).toBeVisible()
    await expect(page.getByText('bez DPH')).toBeVisible()

    setVatPayer(false)
    await page.goto(shopUrl(href!))
    await expect(page.locator('body')).not.toContainText('DPH')

    // The price is still there — this is about the tax lines, not the price.
    await expect(page.locator('[data-variant-price]').first()).toBeVisible()
  })
})
