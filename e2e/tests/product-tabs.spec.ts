import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl, signInAsOwner } from '../support/shop'

/**
 * Where the product form's fields live (wave 3.12).
 *
 * Categories and the variant control used to sit at the bottom of "Základní",
 * which is where a merchant filling in a new product runs out of patience.
 */
test.describe('product detail tabs', () => {
  let slug = ''

  test.beforeAll(() => {
    slug = artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        echo Modules\\Products\\Models\\Product::query()->value('slug');
      });
    `).trim()
  })

  test.beforeEach(async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl(`/admin/m/products/${slug}`))
  })

  test('categories and the variant control have left the first tab', async ({ page }) => {
    await expect(page.locator('#p-primary-category')).toBeHidden()
    await expect(page.locator('#p-variant-display')).toBeHidden()
  })

  test('categories are on their own tab', async ({ page }) => {
    await page.getByRole('tab', { name: 'Kategorie' }).click()

    await expect(page.locator('#p-primary-category')).toBeVisible()
    // And Save still belongs to this tab, because the form saves from it.
    await expect(page.getByRole('button', { name: 'Uložit', exact: true })).toBeVisible()
  })

  test('the variant control is on the settings tab', async ({ page }) => {
    await page.getByRole('tab', { name: 'Nastavení' }).click()

    await expect(page.locator('#p-variant-display')).toBeVisible()
  })

  /**
   * The move must not have detached the fields from the form: a select that
   * saves nothing looks identical to one that does.
   */
  test('a choice made on the new tab is saved', async ({ page }) => {
    await page.getByRole('tab', { name: 'Nastavení' }).click()
    await page.locator('#p-variant-display').selectOption('select')

    await page.getByRole('button', { name: 'Uložit', exact: true }).click()
    await expect(page.getByText('Produkt byl uložen.')).toBeVisible()

    await page.reload()
    await page.getByRole('tab', { name: 'Nastavení' }).click()
    await expect(page.locator('#p-variant-display')).toHaveValue('select')

    // By label: the "inherit" option is bound to null, so it has no value
    // attribute to select by.
    await page.locator('#p-variant-display').selectOption({ label: 'Zdědit z nastavení obchodu' })
    await page.getByRole('button', { name: 'Uložit', exact: true }).click()
    await expect(page.getByText('Produkt byl uložen.')).toBeVisible()
  })
})
