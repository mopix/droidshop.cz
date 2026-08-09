import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { artisanEval, shopUrl, signInAsOwner } from '../support/shop'

/**
 * The listing thumbnail and its hover preview (wave 3.11).
 *
 * Growth on hover is geometry, and geometry is something only a rendered page
 * can answer.
 */
test.describe('product listing thumbnail', () => {
  test.beforeAll(() => {
    // Seeded server-side: the demo shop ships no product images, and the
    // browser must not upload one (see product-images.spec.ts).
    artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        $product = Modules\\Products\\Models\\Product::query()->firstOrFail();

        if (! $product->images()->exists()) {
          $tmp = tempnam(sys_get_temp_dir(), 'e2e').'.png';
          file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

          app(Modules\\Products\\Services\\ProductImageService::class)->add(
            $product,
            new Illuminate\\Http\\UploadedFile($tmp, 'nahled.png', 'image/png', null, true),
          );
        }
      });
    `)
  })

  test('the preview floats over the table without moving it', async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl('/admin/m/products'))

    // `:has(img)` on purpose: the product's name is a link to the same place,
    // and it is the one without a picture in it.
    const link = page.locator('tbody a:has(img)').first()
    const thumb = link.locator('img').first()
    const preview = link.locator('img').nth(1)
    const firstRow = link.locator('xpath=ancestor::tr')

    const rowBefore = await firstRow.boundingBox()
    const thumbBefore = await thumb.boundingBox()

    await expect(preview).toBeHidden()

    await thumb.hover()
    await expect(preview).toBeVisible()

    // The complaint this fixes: the row used to grow and shove the table
    // around. It must not move at all.
    const rowAfter = await firstRow.boundingBox()
    expect(rowAfter!.height).toBe(rowBefore!.height)
    expect((await thumb.boundingBox())!.width).toBe(thumbBefore!.width)

    // And the preview is genuinely bigger than the thumbnail it came from.
    expect((await preview.boundingBox())!.width).toBeGreaterThan(thumbBefore!.width * 2)

    await page.mouse.move(0, 0)
    await expect(preview).toBeHidden()

    // The same from the keyboard: hovering is not something a keyboard does.
    await link.focus()
    await expect(preview).toBeVisible()
  })

  /**
   * The listing lives in a horizontally scrolling wrapper, which clips
   * vertically as well. An absolutely positioned preview would be cut off at
   * the table's edge; a fixed one is not, and the last row is where that shows.
   */
  test('the preview is not clipped by the scrolling table', async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl('/admin/m/products'))

    const lastLink = page.locator('tbody a:has(img)').last()
    await lastLink.locator('img').first().hover()

    const preview = lastLink.locator('img').nth(1)
    await expect(preview).toBeVisible()

    const box = await preview.boundingBox()
    const wrapper = await page.locator('table').first().locator('..').boundingBox()

    // Whether it actually overflows depends on how many rows the demo has, so
    // the binding assertion is that the browser reports it as fixed — which is
    // what escapes the clipping.
    expect(box!.height).toBeGreaterThan(100)
    expect(
      await preview.evaluate((el) => getComputedStyle(el).position),
    ).toBe('fixed')
    expect(wrapper).not.toBeNull()
  })

  test('the listing has no blocking accessibility violations', async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl('/admin/m/products'))

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze()

    expect(results.violations).toEqual([])
  })
})
