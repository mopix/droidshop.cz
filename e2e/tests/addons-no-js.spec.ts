import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl } from '../support/shop'

/**
 * Buying an accessory with JavaScript switched off.
 *
 * The PHPUnit suite proves the arithmetic; this proves the path a shopper
 * actually walks — pick a frame, order, and find both lines on the order.
 * Accessories change what the customer pays, so the no-JS path is not a
 * nicety here (§16.3).
 */
test.describe('an accessory, without JavaScript', () => {
  test('is chosen on the product page and reaches the order', async ({ page }) => {
    // Seeded through artisan rather than the admin: this test is about the
    // shopper's path, and building the offer through five admin screens first
    // would make a failure anywhere in them look like a failure here.
    const slug = artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.${process.env.E2E_HOST ?? 'droidshop'}'))->firstOrFail();

      echo app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        $product = Modules\\Products\\Models\\Product::query()->firstOrFail();

        $group = Modules\\Products\\Models\\ProductAddonGroup::query()->firstOrCreate(
          ['product_id' => $product->id, 'label' => 'Rám'],
          ['required' => false, 'position' => 0],
        );

        Modules\\Products\\Models\\ProductAddon::query()->firstOrCreate(
          ['group_id' => $group->id, 'label' => 'Dubový rám'],
          ['price' => 26900, 'tax_rate_id' => app(App\\Core\\Tax\\TaxRates::class)->default()->id, 'position' => 0],
        );

        return $product->slug;
      });
    `).trim()

    await page.goto(shopUrl(`/produkt/${slug}`))

    await page.getByRole('button', { name: 'Odmítnout vše' }).click()

    // The label carries the picture and the price; the radio inside it is
    // visually hidden, which is why the label is what gets clicked.
    await page.getByText('Dubový rám').click()

    // Submitted from the keyboard: the cookie banner is fixed to the bottom
    // and, with JavaScript off, nothing ever removes it, so on a short page it
    // covers the button (recorded in the wave 4.1 as-is).
    await page.locator('form[action*="/kosik"] button[type="submit"]').first().press('Enter')

    await expect(page).toHaveURL(/\/kosik/)
    await expect(page.getByText('Dubový rám')).toBeVisible()

    // The accessory has no controls of its own — it follows its product.
    await expect(page.locator('form[action*="/kosik/"] input[name="quantity"]')).toHaveCount(1)
  })
})
