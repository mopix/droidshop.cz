<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The debt wave 2.6 carried forward: a shipping or payment fee without a
 * tax_rate_id was charged to the customer but silently dropped from the VAT
 * recapitulation, so an invoice total did not match its own tax rows (AK 4).
 *
 * A VAT payer must now name the rate; a shop that is not a payer has no
 * recapitulation to be missing from and may leave it empty.
 */
class FeeVatTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function shop(bool $vatPayer): array
    {
        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['vat_payer' => $vatPayer]);
        $this->activateModule($tenant, 'shipping');

        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop/admin/m/shipping'.$path;
    }

    public function test_a_vat_paying_shop_must_pick_a_rate_for_a_shipping_fee(): void
    {
        [, $owner] = $this->shop(vatPayer: true);

        $this->actingAs($owner)
            ->post($this->url('/zpusoby-dopravy'), [
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr',
                'price' => 9900,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('tax_rate_id');
    }

    public function test_a_vat_paying_shop_must_pick_a_rate_for_a_payment_fee(): void
    {
        [, $owner] = $this->shop(vatPayer: true);

        $this->actingAs($owner)
            ->post($this->url('/zpusoby-platby'), [
                'provider' => PaymentMethod::PROVIDER_COD,
                'name' => 'Dobírka',
                'fee' => 2900,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('tax_rate_id');
    }

    public function test_a_vat_paying_shop_saves_once_the_rate_is_named(): void
    {
        [$tenant, $owner] = $this->shop(vatPayer: true);

        $rateId = $this->context->runAs($tenant, fn () => app(TaxRates::class)->default()->id);

        $this->actingAs($owner)
            ->post($this->url('/zpusoby-dopravy'), [
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr',
                'price' => 9900,
                'tax_rate_id' => $rateId,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * AK 9 — the recapitulation covers the whole amount, fees included. This
     * is the invariant the missing rate used to break.
     */
    public function test_the_vat_breakdown_of_an_order_adds_up_to_its_total(): void
    {
        [$tenant] = $this->shop(vatPayer: true);

        foreach (['storefront', 'checkout', 'products', 'orders'] as $module) {
            $this->activateModule($tenant, $module);
        }

        $this->context->runAs($tenant, function () {
            $rateId = app(TaxRates::class)->default()->id;

            $product = app(ProductWriter::class)->create([
                'name' => 'Klávesnice Acme',
                'price' => 100_000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $rateId,
            ]);

            $shipping = ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr',
                'price' => 9_900,
                'tax_rate_id' => $rateId,
                'is_active' => true,
            ]);

            $payment = PaymentMethod::create([
                'provider' => PaymentMethod::PROVIDER_COD,
                'name' => 'Dobírka',
                'fee' => 2_900,
                'tax_rate_id' => $rateId,
                'is_active' => true,
            ]);

            $cart = app(CartRepository::class)->forToken(null);
            app(CartRepository::class)->addItem($cart, $product->id, 1);
            app(CartRepository::class)->chooseShipping($cart, $shipping->id, $payment->id);

            $fresh = $cart->fresh();

            app(OrderPlacement::class)->place(
                new PlacementRequest(
                    cart: $fresh,
                    shippingMethodId: $shipping->id,
                    paymentMethodId: $payment->id,
                    email: 'jana@example.cz',
                    phone: '+420777123456',
                    billing: [
                        'name' => 'Jana Nováková',
                        'street' => 'Hlavní 1',
                        'city' => 'Praha',
                        'zip' => '11000',
                        'country' => 'CZ',
                    ],
                    shipping: null,
                    checkoutToken: 'test-fee-vat',
                ),
            );
        });

        // Read back through the module's own model: place() returns the
        // PlacedOrder contract, which deliberately does not expose the stored
        // recapitulation.
        $order = $this->context->runAs($tenant, fn () => Order::query()->firstOrFail());

        $summed = collect($order->vat_summary)->sum(fn (array $row) => $row['base'] + $row['vat']);

        $this->assertSame(112_800, $order->total->amount);
        $this->assertSame($order->total->amount, $summed);
    }

    public function test_a_shop_that_is_not_a_vat_payer_may_leave_the_rate_empty(): void
    {
        [, $owner] = $this->shop(vatPayer: false);

        $this->actingAs($owner)
            ->post($this->url('/zpusoby-dopravy'), [
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr',
                'price' => 9900,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();
    }
}
