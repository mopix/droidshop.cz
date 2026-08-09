import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl, signInAsOwner } from '../support/shop'

/**
 * The EAN field warns instead of blocking (wave 3.12).
 *
 * The check digit is computed from the digits before it, so a made-up number
 * almost never passes — which is why this used to make a product unsaveable.
 */
test.describe('product EAN', () => {
  let slug = ''

  test.beforeAll(() => {
    slug = artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        echo Modules\\Products\\Models\\Product::query()->value('slug');
      });
    `).trim()
  })

  test('a made-up code warns but still saves', async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl(`/admin/m/products/${slug}`))

    await page.locator('#p-ean').fill('1234567890123')

    const note = page.locator('#p-ean-note')
    await expect(note).toContainText('do feedů')
    // And it says what the digit ought to be, which is the only actionable
    // thing about a wrong check digit.
    await expect(note).toContainText('Poslední číslice by měla být 8')

    await page.getByRole('button', { name: 'Uložit', exact: true }).click()
    await expect(page.getByText('Produkt byl uložen.')).toBeVisible()

    await expect(page.locator('#p-ean')).toHaveValue('1234567890123')
  })

  test('the missing check digit is offered', async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl(`/admin/m/products/${slug}`))

    await page.locator('#p-ean').fill('859400123456')
    await expect(page.locator('#p-ean-note')).toContainText('Chybí kontrolní číslice')

    await page.getByRole('button', { name: /Doplnit/ }).click()

    await expect(page.locator('#p-ean')).toHaveValue('8594001234561')
    await expect(page.locator('#p-ean-note')).toHaveCount(0)
  })

  test.afterAll(() => {
    artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        Modules\\Products\\Models\\Product::query()->update(['ean' => null]);
      });
    `)
  })
})
