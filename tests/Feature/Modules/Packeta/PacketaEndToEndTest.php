<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Checkout\Models\Cart;
use Modules\Orders\Models\Order;
use Modules\Packeta\Models\PickupPoint;
use Modules\Packeta\Models\Shipment;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

/**
 * The whole journey over plain HTTP, no JavaScript anywhere: catalogue → cart
 * → delivery → pickup point → details → placed order → submitted parcel →
 * tracking link. Every earlier task's test in this wave proves one piece of
 * this in isolation; this one proves they fit together end to end, which is
 * the acceptance criterion that actually matters (spec §16.3,
 * .claude/rules/storefront-rendering.md: "celý checkout funkční bez JS").
 *
 * Every step is a real HTTP request against the tenant's own host — never a
 * direct call into CartRepository, OrderPlacement or ShipmentSubmitter — so a
 * pass here is proof about the wired-together routes/controllers/views, not
 * about the services underneath them (those already have their own unit and
 * feature coverage in tasks 1-15).
 *
 * The shopper is logged in as a customer (App\Core\Tenancy\TenantContext-scoped
 * Modules\Customers\Models\Customer) throughout, via the same
 * Tests\Concerns\ActsAsCustomer helper Tests\Feature\Modules\Packeta\ShipmentTrackingTest
 * and Tests\Feature\Modules\Customers\CustomerAccountTest use — re-asserted on
 * every request the way those tests do, rather than assumed to survive across
 * calls — so the placed order carries a customer_id and the final step can
 * read it back from "their own" account.
 */
class PacketaEndToEndTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        // Deterministic regardless of whatever .env happens to hold — the
        // tracking assertion at the end depends on this exact template.
        config()->set('packeta.tracking_url', 'https://tracking.test/{barcode}');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        // 'products' and 'categories' cascade in automatically (checkout
        // requires products, products requires categories — see
        // App\Core\Modules\ModuleRegistry::guardDependencies()), the same as
        // every other checkout feature test in this wave.
        foreach (['storefront', 'customers', 'checkout', 'shipping', 'orders', 'packeta'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    private function adminUrl(string $path): string
    {
        return 'http://shop1.droidshop/admin/m/packeta'.$path;
    }

    private function cartToken(): string
    {
        return $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->token);
    }

    public function test_a_shopper_buys_with_zasilkovna_without_javascript(): void
    {
        // --- Fixtures: one product, one Zásilkovna shipping method with real
        // credentials (the real EloquentCarrierRegistry resolves a driver from
        // these alone — no FakeCarrierRegistry substitution anywhere in this
        // test, exactly because a fake driver would prove nothing about the
        // real one), one COD payment method, one active pickup point, and the
        // customer who will place the order.
        [$product, $shipping, $payment, $point, $customer] = $this->context->runAs(
            $this->tenant,
            function (): array {
                $product = app(ProductWriter::class)->create([
                    'name' => 'Klávesnice Acme',
                    'price' => 100_000, // 1 000,00 Kč
                    'status' => Product::STATUS_ACTIVE,
                    'tax_rate_id' => app(TaxRates::class)->default()->id,
                    'weight_g' => 750,
                ]);

                $shipping = ShippingMethod::create([
                    'provider' => ShippingMethod::PROVIDER_PACKETA,
                    'name' => 'Zásilkovna',
                    'price' => 5_900, // 59,00 Kč
                    'is_active' => true,
                    'settings' => ['api_password' => 's3cr3t', 'eshop' => 'esh-1'],
                ]);

                $payment = PaymentMethod::create([
                    'provider' => PaymentMethod::PROVIDER_COD,
                    'name' => 'Dobírka',
                    'fee' => 0,
                    'currency' => 'CZK',
                    'tax_rate_id' => app(TaxRates::class)->default()->id,
                    'is_active' => true,
                ]);

                $point = PickupPoint::create([
                    'carrier' => ShippingMethod::PROVIDER_PACKETA,
                    'code' => '1001',
                    'name' => 'Brno — Hlavní nádraží',
                    'street' => 'Nádražní 1',
                    'city' => 'Brno',
                    'zip' => '60200',
                    'country' => 'CZ',
                    'search_text' => PickupPoint::normalise('Brno — Hlavní nádraží Nádražní 1 Brno 60200'),
                    'is_active' => true,
                ]);

                $customer = $this->makeCustomer($this->tenant);

                return [$product, $shipping, $payment, $point, $customer];
            },
        );

        // --- Step 1: catalogue. A real product page, not a client-side
        // fetch — the price and the add-to-cart form are already in the raw
        // HTML.
        $productPage = $this->actingAsCustomer($customer)->get($this->url('/produkt/'.$product->slug));
        $productPage->assertOk();
        $productPage->assertSee('Klávesnice Acme');
        $productPage->assertSee('<form method="POST" action="'.$this->url('/kosik').'"', false);

        // --- Step 2: add to cart. A real POST, redirecting to a freshly
        // rendered /kosik.
        $add = $this->actingAsCustomer($customer)
            ->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $add->assertRedirect($this->url('/kosik'));

        $token = $this->cartToken();

        // --- Step 3: /pokladna/doprava lists Zásilkovna — proof the driver
        // really resolved from the settings saved above, not a stub.
        $shippingPage = $this->actingAsCustomer($customer)
            ->withCookie('cart_token', $token)
            ->get($this->url('/pokladna/doprava'));
        $shippingPage->assertOk();
        $shippingPage->assertSee('Zásilkovna');
        $shippingPage->assertSee('Vybrat výdejní místo');

        // --- Step 4: choose the shipping method (payment options only
        // appear once a shipping method is selected — see
        // Modules/Checkout/Resources/views/checkout/shipping.blade.php).
        $chooseShipping = $this->actingAsCustomer($customer)
            ->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), ['shipping_method_id' => $shipping->id]);
        $chooseShipping->assertRedirect($this->url('/pokladna/doprava'));

        // --- Step 5: search the pickup point catalogue by city, in raw HTML.
        $pickupSearch = $this->actingAsCustomer($customer)
            ->withCookie('cart_token', $token)
            ->get($this->url('/pokladna/vydejni-misto?q=Brno'));
        $pickupSearch->assertOk();
        $pickupSearch->assertSee('Brno — Hlavní nádraží');
        $pickupSearch->assertSee('Nádražní 1');
        $pickupSearch->assertSee('<form method="POST"', false);
        $pickupSearch->assertSee('name="pickup_point_code"', false);

        // --- Step 6: choose it. Only the code is trusted server-side — a
        // spoofed name/address in the POST body must never end up anywhere
        // (same policy as a spoofed price, and the same as
        // PickupPointCheckoutTest::test_choosing_a_point_stores_only_its_code()).
        $choosePoint = $this->actingAsCustomer($customer)
            ->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/vydejni-misto'), [
                'pickup_point_code' => '1001',
                'name' => 'Podvržený název',
                'street' => 'Vymyšlená 1',
                'city' => 'Nikde',
            ]);
        $choosePoint->assertRedirect($this->url('/pokladna/doprava'));
        $choosePoint->assertSessionDoesntHaveErrors();

        // --- Step 7: back on /pokladna/doprava, the REAL point (from the
        // catalogue) is printed, never the forged one, and payment options
        // are now on the page.
        $shippingPageAgain = $this->actingAsCustomer($customer)
            ->withCookie('cart_token', $token)
            ->get($this->url('/pokladna/doprava'));
        $shippingPageAgain->assertOk();
        $shippingPageAgain->assertSee('Brno — Hlavní nádraží');
        $shippingPageAgain->assertSee('Nádražní 1');
        $shippingPageAgain->assertDontSee('Podvržený název');
        $shippingPageAgain->assertSee('Dobírka');

        // --- Step 8: finish choosing the payment method too (a shopper's
        // single submit of the shipping+payment form, now that both radio
        // groups are on the page).
        $choosePayment = $this->actingAsCustomer($customer)
            ->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/doprava'), [
                'shipping_method_id' => $shipping->id,
                'payment_method_id' => $payment->id,
            ]);
        $choosePayment->assertRedirect($this->url('/pokladna/doprava'));

        // --- Step 9: the details/recap page. A real form with a real hidden
        // idempotency token, never a container waiting for a fetch.
        $detailsPage = $this->actingAsCustomer($customer)
            ->withCookie('cart_token', $token)
            ->get($this->url('/pokladna/udaje'));
        $detailsPage->assertOk();
        $detailsPage->assertSee('<form method="POST" action="'.$this->url('/pokladna/udaje').'"', false);
        preg_match('/name="checkout_token" value="([^"]+)"/', $detailsPage->getContent(), $matches);
        $checkoutToken = $matches[1] ?? $this->fail('checkout_token hidden field not found on /pokladna/udaje.');

        // --- Step 10: place the order. No price, no shipping/payment ids and
        // no pickup point data are posted here at all (AK 5) — only contact
        // and address fields plus the idempotency token.
        $place = $this->actingAsCustomer($customer)
            ->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/udaje'), [
                'checkout_token' => $checkoutToken,
                'email' => 'jana@example.cz',
                'phone' => '+420777123456',
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
                'terms' => '1',
            ]);

        // COD has no online gateway (PaymentGatewayRegistry::for('cod') is
        // null), so placing goes straight to the thank-you page rather than
        // away to a gateway.
        $place->assertRedirectContains('/dekujeme/');

        $order = $this->context->runAs(
            $this->tenant,
            fn () => Order::query()->where('checkout_token', $checkoutToken)->firstOrFail(),
        );

        // --- Snapshot check: shipping_snapshot['pickup_point'] must be the
        // catalogue's own row, never anything from the request body (the
        // forged 'Podvržený název' from Step 6 must never appear here).
        $pickupSnapshot = $order->shipping_snapshot['pickup_point'] ?? null;
        $this->assertNotNull($pickupSnapshot, 'Order has no pickup_point in its shipping_snapshot.');
        $this->assertSame($point->code, $pickupSnapshot['code']);
        $this->assertSame($point->name, $pickupSnapshot['name']);
        $this->assertSame($point->street, $pickupSnapshot['street']);
        $this->assertSame($point->city, $pickupSnapshot['city']);
        $this->assertSame($point->zip, $pickupSnapshot['zip']);
        $this->assertSame(ShippingMethod::PROVIDER_PACKETA, $pickupSnapshot['provider']);
        $this->assertSame(750, $pickupSnapshot['weight_grams']);
        $this->assertSame(Order::PAYMENT_UNPAID, $order->payment_status);
        $this->assertSame(Order::FULFILLMENT_NEW, $order->fulfillment_status);

        // Total = 1 000,00 (product) + 59,00 (shipping) + 0,00 (payment fee)
        // = 1 059,00 Kč = 105 900 haléře — asserted here as the figure the
        // carrier call below must also carry as its COD amount.
        $this->assertSame(105_900, $order->total->amount);

        // --- Step 11: admin hands the parcel to the carrier. Http::fake
        // stands in for Zásilkovna's real API only — everything upstream
        // (route, permission gate, ShipmentSubmitter, PacketaCarrier,
        // PacketaClient's XML) is exercised for real.
        Http::fake(['*' => Http::response(
            '<response><status>ok</status><result><id>777</id><barcode>Z1234567890</barcode></result></response>',
        )]);

        $owner = User::factory()->create();
        $this->tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        // Guard explicit ('web', not the driver-default): Tests\Concerns\
        // ActsAsCustomer::actingAsCustomer() above switched Auth's DEFAULT
        // guard to 'customer' (Illuminate\Auth\AuthManager::shouldUse()
        // rewrites config('auth.defaults.guard')), so an unqualified
        // actingAs($owner) here would silently log $owner into the
        // 'customer' guard instead of 'web' and EnsureTenantMember would
        // still see no 'web' user.
        $submit = $this->actingAs($owner, 'web')
            ->post($this->adminUrl('/zasilky/podat'), ['order_uuids' => [$order->uuid]]);

        $submit->assertRedirect();
        $this->assertSame('Podáno 1 zásilek.', (string) session('status'));

        // --- What went out to the carrier: the order number, the exact
        // pickup point code (from the catalogue, not the request), the
        // weight in kilograms, and — because payment is COD — the amount due
        // at the door.
        Http::assertSent(function (HttpRequest $request) use ($order) {
            $body = $request->body();

            return str_contains($body, '<createPacket>')
                && str_contains($body, '<number>'.$order->number.'</number>')
                && str_contains($body, '<addressId>1001</addressId>')
                && str_contains($body, '<weight>0.75</weight>')
                && str_contains($body, '<cod>1059.00</cod>')
                && str_contains($body, '<value>1059.00</value>');
        });

        $shipment = $this->context->runAs(
            $this->tenant,
            fn () => Shipment::query()->where('order_id', $order->id)->firstOrFail(),
        );
        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->shipmentStatus());
        $this->assertSame('777', $shipment->packet_id);
        $this->assertSame('Z1234567890', $shipment->barcode);

        // --- Step 12: the customer sees a tracking link on their own order,
        // built from the barcode the carrier just handed back.
        $orderPage = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$order->uuid));
        $orderPage->assertOk();
        $orderPage->assertSee('Sledování zásilky');
        $orderPage->assertSee('Z1234567890');
        $orderPage->assertSee('https://tracking.test/Z1234567890', false);
    }
}
