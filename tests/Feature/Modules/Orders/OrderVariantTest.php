<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Services\OrderEditor;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * An order line is a snapshot: it has to stay readable after the variant it
 * names is renamed, deactivated or deleted outright.
 */
class OrderVariantTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'products', 'categories', 'checkout', 'shipping', 'orders', 'customers'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    /**
     * @return array{product: Product, variant: ProductVariant}
     */
    private function shirt(int $stock = 5): array
    {
        return $this->context->runAs($this->tenant, function () use ($stock) {
            $taxRate = app(TaxRates::class)->default();

            $product = app(ProductWriter::class)->create([
                'name' => 'Tričko Acme',
                'price' => 49900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $taxRate->id,
            ]);

            $size = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $value = $size->values()->create(['value' => 'M', 'position' => 0]);

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'position' => 0,
                'price' => 52900,
                'stock_tracked' => true,
                'stock_qty' => $stock,
            ]);
            $variant->optionValues()->attach($value->id);

            return ['product' => $product, 'variant' => $variant];
        });
    }

    /**
     * @param  array{product: Product, variant: ProductVariant}  $data
     */
    private function placeOrderWithVariant(array $data, int $quantity = 1): Order
    {
        return $this->context->runAs($this->tenant, function () use ($data, $quantity) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $data['product']->id, $quantity, $data['variant']->id);

            $placed = app(OrderPlacement::class)->place(new PlacementRequest(
                cart: $cart,
                shippingMethodId: null,
                paymentMethodId: null,
                email: 'zakaznik@example.com',
                phone: null,
                billing: ['name' => 'Jan Novák', 'street' => 'Dlouhá 1', 'city' => 'Praha', 'zip' => '11000'],
                shipping: null,
                checkoutToken: 'test-'.$data['variant']->id.'-'.$quantity,
                customerId: null,
                source: Order::SOURCE_STOREFRONT,
                note: null,
            ));

            return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
        });
    }

    public function test_the_order_line_snapshots_the_variant_id_price_and_label(): void
    {
        $data = $this->shirt();

        $order = $this->placeOrderWithVariant($data);

        $this->context->runAs($this->tenant, function () use ($order, $data) {
            $line = $order->items()->firstOrFail();

            $this->assertSame($data['variant']->id, (int) $line->variant_id);
            $this->assertSame('Velikost: M', $line->variant_label);
            $this->assertSame(52900, $line->unit_price->amount);
        });
    }

    public function test_placing_the_order_takes_stock_from_the_variant(): void
    {
        $data = $this->shirt(stock: 5);

        $this->placeOrderWithVariant($data, quantity: 2);

        $this->context->runAs($this->tenant, function () use ($data) {
            $this->assertSame(3, ProductVariant::query()->whereKey($data['variant']->id)->value('stock_qty'));
        });
    }

    public function test_the_snapshot_survives_the_variant_being_deleted(): void
    {
        $data = $this->shirt();

        $order = $this->placeOrderWithVariant($data);

        $this->context->runAs($this->tenant, function () use ($order, $data) {
            ProductVariant::query()->whereKey($data['variant']->id)->delete();

            $line = $order->fresh()->items()->firstOrFail();

            $this->assertSame('Velikost: M', $line->variant_label);
            $this->assertSame(52900, $line->unit_price->amount);
        });
    }

    /**
     * A variant deactivated between "add to cart" and "submit" cannot be
     * fulfilled — the same class of failure as running out of stock.
     */
    public function test_a_deactivated_variant_refuses_placement(): void
    {
        $data = $this->shirt();

        $this->context->runAs($this->tenant, function () use ($data) {
            $data['variant']->update(['active' => false]);
        });

        $this->expectException(InsufficientStock::class);

        $this->placeOrderWithVariant($data);
    }

    public function test_cancelling_an_order_returns_stock_to_the_variant(): void
    {
        $data = $this->shirt(stock: 5);

        $order = $this->placeOrderWithVariant($data, quantity: 2);

        $this->context->runAs($this->tenant, function () use ($order) {
            $this->assertSame(3, ProductVariant::query()->firstOrFail()->stock_qty);

            app(OrderEditor::class)->cancel(
                $order,
                'zákazník si to rozmyslel',
                returnStock: true,
                sendEmail: false,
                actorType: OrderEvent::ACTOR_ADMIN,
                actorId: null,
            );
        });

        $this->context->runAs($this->tenant, function () use ($data) {
            $this->assertSame(5, ProductVariant::query()->whereKey($data['variant']->id)->value('stock_qty'));
        });
    }
}
