import { expect, test } from '@playwright/test'
import { artisanEval, seedHomeDeliveryShipping, shopUrl } from '../support/shop'

/**
 * Delivery to the shopper's own address through a Packeta partner carrier
 * (task 5), driven **without JavaScript** (§16.3) — the same binding
 * acceptance criterion checkout-no-js.spec.ts already proves for the
 * pickup-point path.
 *
 * Runs in the `no-js` Playwright project (javaScriptEnabled: false); the
 * filename must match /no-js/ or Playwright would run it with JS and the
 * test would prove nothing about the acceptance criterion it exists for.
 *
 * The one thing this driver has to demonstrate that the existing pickup-
 * point test does not: PacketaHomeDelivery::requiresPickupPoint() === false
 * means checkout never shows a "Vybrat výdejní místo" step for it — proven
 * both by absence of the link on the page AND by reaching the thank-you
 * page in one fewer step than the pickup-point flow needs.
 */
test.describe('home delivery through a partner carrier, without JavaScript', () => {
  test('a customer can order delivery to their own address, with no pickup-point step at all', async ({ page }) => {
    seedHomeDeliveryShipping()

    // The cookie banner never hides without JavaScript (wave 3.3: it is
    // baked into cached HTML and a script hides it, and there is no script
    // here) — it stays `fixed` to the bottom of the viewport regardless of
    // scroll position. A "Pokračovat" button that the browser's own
    // scroll-into-view lands near the bottom edge can end up underneath it,
    // so this scrolls the whole page fully down first: the actual click
    // target then sits well clear of the banner's fixed strip rather than
    // right at its edge, exactly what a real visitor scrolling down to the
    // button would end up doing.
    const clearBanner = () => page.mouse.wheel(0, 100_000)

    // 1. Catalogue.
    await page.goto(shopUrl('/'))

    const productLink = page.locator('a[href*="/produkt/"]').first()
    await expect(productLink).toBeVisible()
    await productLink.click()

    // 2. Product detail.
    await expect(page).toHaveURL(/\/produkt\//)
    await page.getByRole('button', { name: 'Přidat do košíku' }).click()

    // 3. Cart.
    await expect(page).toHaveURL(/\/kosik/)
    await page.getByRole('link', { name: /Pokračovat k pokladně/ }).click()

    // 4. Shipping — choose home delivery. No "Vybrat výdejní místo" link may
    // appear anywhere on this page for it (the defining assertion for this
    // driver): requiresPickupPoint() === false is what task 5 adds, and a
    // regression here would silently strand a shopper on a step that asks
    // for a branch this carrier will never use.
    await expect(page).toHaveURL(/\/pokladna\/doprava/)
    const homeDeliveryLabel = page.locator('label', { hasText: 'Zásilkovna – doručení na adresu' })
    await expect(homeDeliveryLabel).toBeVisible()
    await homeDeliveryLabel.locator('input[type="radio"]').check()
    await expect(page.getByRole('link', { name: /Vybrat výdejní místo/ })).toHaveCount(0)
    await clearBanner()
    await page.getByRole('button', { name: 'Pokračovat' }).click()

    // 5. Payment — same round trip the pickup-point path needs, since which
    // payments are offered still depends on the chosen carrier.
    await expect(page).toHaveURL(/\/pokladna\/doprava/)
    await expect(page.getByRole('link', { name: /Vybrat výdejní místo/ })).toHaveCount(0)
    const payment = page.locator('input[name="payment_method_id"]').first()
    await expect(payment, 'no payment method was offered for the chosen carrier').toBeVisible()
    await payment.check()
    await clearBanner()
    await page.getByRole('button', { name: 'Pokračovat' }).click()

    // 6. Details — straight here, never a pickup-point picker in between.
    await expect(page).toHaveURL(/\/pokladna\/udaje/)
    await page.fill('input[name="email"]', 'e2e-hd@example.com')
    await page.fill('input[name="phone"]', '777123456')
    await page.fill('input[name="name"]', 'Petr Svoboda')
    await page.fill('input[name="street"]', 'Doručovací 5')
    await page.fill('input[name="city"]', 'Brno')
    await page.fill('input[name="zip"]', '60200')
    await page.check('input[name="terms"]')

    await clearBanner()
    await page.getByRole('button', { name: /Objednat s povinností platby/ }).click()

    // 7. Thank you.
    await expect(page).toHaveURL(/\/dekujeme\//)
    await expect(page.getByText(/Děkujeme za objednávku/)).toBeVisible()

    // The rendered page alone only proves the template — the brief is
    // explicit that this must also prove a real order exists carrying the
    // home-delivery provider on its snapshot. Read through the same
    // artisan bridge the fixture setup used, under the tenant's own context
    // (Order carries BelongsToTenant), rather than trusting the thank-you
    // copy.
    const snapshotJson = artisanEval(`
      $t = App\\Models\\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'obchod.droidshop'))->firstOrFail();
      app(App\\Core\\Tenancy\\TenantContext::class)->runAs($t, function () {
        $order = Modules\\Orders\\Models\\Order::where('email', 'e2e-hd@example.com')->latest('id')->firstOrFail();
        echo json_encode($order->shipping_snapshot);
      });
    `)
    const snapshot = JSON.parse(snapshotJson)

    expect(snapshot.provider).toBe('packeta_hd')
    // No pickup point was ever collected for this order — a leftover one
    // from an earlier scenario picking the same carrier key would be the
    // exact regression PacketaHomeDelivery::requiresPickupPoint() exists to
    // prevent.
    expect(snapshot.pickup_point ?? null).toBeNull()
  })
})
