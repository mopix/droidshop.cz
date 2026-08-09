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

  test('the thumbnail grows on hover and on keyboard focus', async ({ page }) => {
    await signInAsOwner(page)
    await page.goto(shopUrl('/admin/m/products'))

    const thumb = page.locator('tbody img').first()
    const before = await thumb.boundingBox()

    expect(before!.width).toBeGreaterThan(0)

    await thumb.hover()
    await expect
      .poll(async () => (await thumb.boundingBox())!.width)
      .toBeGreaterThan(before!.width)

    // Away again, and it goes back — a preview that sticks is a layout that
    // never settles.
    await page.mouse.move(0, 0)
    await expect.poll(async () => (await thumb.boundingBox())!.width).toBe(before!.width)

    // The same from the keyboard: hovering is not something a keyboard does.
    await thumb.locator('..').focus()
    await expect
      .poll(async () => (await thumb.boundingBox())!.width)
      .toBeGreaterThan(before!.width)
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
