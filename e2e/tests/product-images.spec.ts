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

  /**
   * The whole point of the drop area: a file let go over it uploads. Possible
   * again since wave 3.11 — see the note above.
   */
  test('a dropped file is uploaded', async ({ page }) => {
    const before = await page.locator('#panel-images li').count()

    await page.evaluate(() => {
      const png = Uint8Array.from(
        atob(
          'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ),
        (c) => c.charCodeAt(0),
      )
      const transfer = new DataTransfer()
      transfer.items.add(new File([png], 'pretazeny.png', { type: 'image/png' }))

      document
        .querySelector('#panel-images [class*="border-dashed"]')!
        .dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true }))
    })

    await expect(page.locator('#panel-images li')).toHaveCount(before + 1)
  })

  /**
   * Buttons, not dragging: the order has to be changeable from a keyboard.
   */
  test('images can be reordered with the buttons', async ({ page }) => {
    const items = page.locator('#panel-images li')

    while ((await items.count()) < 2) {
      const before = await items.count()

      await page.setInputFiles('#p-images', {
        name: `poradi-${before}.png`,
        mimeType: 'image/png',
        buffer: Buffer.from(
          'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
          'base64',
        ),
      })
      await page.getByRole('button', { name: 'Nahrát' }).click()
      await expect(items).toHaveCount(before + 1)
    }

    await items.nth(1).getByRole('button', { name: /Posunout obrázek 2 dopředu/ }).click()

    await expect(page.getByText('Pořadí obrázků bylo uloženo.')).toBeVisible()
  })

})
