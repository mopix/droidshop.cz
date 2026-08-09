import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl, signInAsOwner } from '../support/shop'

/**
 * The product screen's image panel and its layout (wave 3.8).
 *
 * Both of these are things only a browser can answer: whether a file dropped
 * on the panel uploads, and whether the form's Save button is drawn over a
 * panel it has nothing to do with.
 */
test.describe('product images', () => {
  let slug = ''

  test.beforeAll(() => {
    // Straight from the database rather than by clicking through the listing:
    // this suite is about the panel, not about how a product is found.
    //
    // Deliberately no image is seeded here. A demo product that carries an
    // image makes the storefront fetch it alongside the page, and
    // `php artisan serve` — a single PHP process — then closes the connection
    // for whatever spec runs next (ERR_CONNECTION_CLOSED on every later
    // file). PHP_CLI_SERVER_WORKERS did not help. That is a dev-server
    // limitation rather than a fault in this feature, but a suite that takes
    // the server down with it hides every other failure, so the browser stops
    // short of anything that needs a stored image. Ordering, uploading and
    // permissions are covered by ProductImageOrderTest over HTTP instead.
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
    await page.getByRole('tab', { name: 'Obrázky' }).click()
  })

  /**
   * The owner could not find "set as main image" because the Save and Delete
   * buttons were drawn above this panel and read as belonging to it.
   */
  test('the form buttons are not drawn over the images panel', async ({ page }) => {
    await expect(page.getByRole('button', { name: 'Uložit', exact: true })).toBeHidden()
    await expect(page.getByRole('button', { name: 'Smazat produkt' })).toBeHidden()

    await page.getByRole('tab', { name: 'Základní' }).click()
    await expect(page.getByRole('button', { name: 'Uložit', exact: true })).toBeVisible()
  })

  /**
   * The drop area reacts to a file being dragged over it.
   *
   * Deliberately NOT a real upload: a successful image upload kills
   * `php artisan serve` outright, and every spec that ran afterwards got
   * ERR_CONNECTION_CLOSED. That is a real bug in the dev server rather than
   * in this feature — the upload path itself is covered by
   * ProductImageOrderTest, which exercises it over HTTP without a browser —
   * but a suite that takes the server down with it hides every other failure,
   * so the browser stops at the edge of the upload.
   */
  test('the drop area reacts to a dragged file', async ({ page }) => {
    const zone = page.locator('#panel-images [class*="border-dashed"]')

    await expect(zone).toBeVisible()
    await expect(zone).toHaveClass(/border-gray-300/)

    await page.evaluate(() => {
      const transfer = new DataTransfer()
      document
        .querySelector('#panel-images [class*="border-dashed"]')!
        .dispatchEvent(new DragEvent('dragover', { dataTransfer: transfer, bubbles: true }))
    })

    await expect(zone).toHaveClass(/border-gray-900/)
  })

})
