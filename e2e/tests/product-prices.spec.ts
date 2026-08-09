import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl, signInAsOwner } from '../support/shop'

/**
 * The prices tab (wave 3.9).
 *
 * The order of the fields is the whole point of the change, and order is
 * something only a rendered page can answer.
 */
test.describe('product prices tab', () => {
  let slug = ''

  test.beforeAll(() => {
    slug = artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        echo Modules\\Products\\Models\\Product::query()->value('slug');
      });
    `).trim()
  })

  const setVatPayer = (payer: boolean) =>
    artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      $t->update(['vat_payer' => ${payer ? 'true' : 'false'}]);
    `)

  test.afterEach(() => {
    // The demo shop is a VAT payer; the rest of the suite expects it back.
    setVatPayer(true)
  })

  test('a payer reads net, rate, gross — in that order, three times', async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl(`/admin/m/products/${slug}`))
    await page.getByRole('tab', { name: 'Ceny' }).click()

    for (const legend of ['Prodejní cena', 'Nákupní cena', 'Akce']) {
      await expect(page.getByRole('group', { name: legend })).toBeVisible()
    }

    // Asserted on geometry, not on source order: what was asked for is how the
    // page reads.
    const sale = page.getByRole('group', { name: 'Prodejní cena' })
    const boxes = await Promise.all(
      ['#p-net-price', '#p-rate', '#p-price'].map((selector) =>
        sale.locator(selector).boundingBox(),
      ),
    )

    expect(boxes[0]!.x).toBeLessThan(boxes[1]!.x)
    expect(boxes[1]!.x).toBeLessThan(boxes[2]!.x)
  })

  /**
   * Regression guard for wave 3.7: a shop that is not registered for VAT is
   * never shown a rate or a net price, and the tidy-up must not have left one
   * behind in the new layout.
   */
  test('a shop that is not registered sees amounts only', async ({ page }) => {
    setVatPayer(false)

    await signInAsOwner(page)
    await page.goto(shopUrl(`/admin/m/products/${slug}`))
    await page.getByRole('tab', { name: 'Ceny' }).click()

    await expect(page.locator('#p-net-price')).toHaveCount(0)
    await expect(page.locator('#p-rate')).toHaveCount(0)
    await expect(page.locator('#p-purchase-rate')).toHaveCount(0)

    await expect(page.locator('#p-price')).toBeVisible()
    await expect(page.locator('#p-sale-percent')).toBeVisible()
  })

  test('typing a percentage previews the amount it works out to', async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl(`/admin/m/products/${slug}`))
    await page.getByRole('tab', { name: 'Ceny' }).click()

    await page.locator('#p-price').fill('1000')
    await page.locator('#p-sale-percent').fill('20')

    await expect(page.getByText(/Vyjde na/)).toContainText('800')

    // The amount field is cleared, so the server computes from the percentage
    // rather than from a figure left behind by an earlier edit.
    await expect(page.locator('#p-sale')).toHaveValue('')
  })
})
