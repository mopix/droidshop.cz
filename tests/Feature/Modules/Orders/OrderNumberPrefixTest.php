<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Settings\SettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The order-number prefix is a tenant setting, not part of the sequence row
 * (wave 2.10) — changing it never rewrites numbers already handed out, the
 * same split InvoiceIssuer uses for document numbering.
 */
class OrderNumberPrefixTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        foreach (['checkout', 'shipping', 'orders'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function prefix(?string $value): void
    {
        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)
            ->setMany('orders', ['number_prefix' => $value]));
    }

    private function placeOrder(): Order
    {
        return $this->context->runAs($this->tenant, function (): Order {
            $product = app(ProductWriter::class)->create([
                'name' => 'Klávesnice Acme',
                'price' => 99_900,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'status' => Product::STATUS_ACTIVE,
            ]);

            /** @var Cart $cart */
            $cart = app(CartRepository::class)->forToken(null);
            app(CartRepository::class)->addItem($cart, $product->id, 1);

            $placed = app(OrderPlacement::class)->place(new PlacementRequest(
                cart: $cart,
                shippingMethodId: null,
                paymentMethodId: null,
                email: 'jana@example.cz',
                phone: '+420777123456',
                billing: [
                    'name' => 'Jana Nováková',
                    'street' => 'Hlavní 1',
                    'city' => 'Praha',
                    'zip' => '110 00',
                    'country' => 'CZ',
                ],
                shipping: null,
                checkoutToken: 'tok-'.bin2hex(random_bytes(8)),
                customerId: null,
                source: 'storefront',
            ));

            return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
        });
    }

    public function test_a_new_order_carries_the_configured_prefix(): void
    {
        $this->prefix('OBJ');

        $this->assertStringStartsWith('OBJ', $this->placeOrder()->number);
    }

    public function test_no_prefix_leaves_the_bare_sequence_number(): void
    {
        $this->assertMatchesRegularExpression('/^\d+$/', $this->placeOrder()->number);
    }

    public function test_existing_numbers_are_untouched_by_a_later_prefix_change(): void
    {
        $first = $this->placeOrder();

        $this->prefix('OBJ');

        $second = $this->placeOrder();

        $this->assertStringStartsNotWith('OBJ', $first->fresh()->number);
        $this->assertStringStartsWith('OBJ', $second->number);
    }
}
