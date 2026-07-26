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
use Modules\Checkout\Models\Cart;
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
    private function cartWithVariant(array $data, int $quantity = 1): Cart
    {
        return $this->context->runAs($this->tenant, function () use ($data, $quantity) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $data['product']->id, $quantity, $data['variant']->id);

            return $cart;
        });
    }

    private function place(Cart $cart, string $checkoutToken): Order
    {
        return $this->context->runAs($this->tenant, function () use ($cart, $checkoutToken) {
            $placed = app(OrderPlacement::class)->place(new PlacementRequest(
                cart: $cart,
                shippingMethodId: null,
                paymentMethodId: null,
                email: 'zakaznik@example.com',
                phone: null,
                billing: ['name' => 'Jan Novák', 'street' => 'Dlouhá 1', 'city' => 'Praha', 'zip' => '11000'],
                shipping: null,
                checkoutToken: $checkoutToken,
                customerId: null,
                source: Order::SOURCE_STOREFRONT,
                note: null,
            ));

            return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
        });
    }

    /**
     * @param  array{product: Product, variant: ProductVariant}  $data
     */
    private function placeOrderWithVariant(array $data, int $quantity = 1): Order
    {
        $cart = $this->cartWithVariant($data, $quantity);

        return $this->place($cart, 'test-'.$data['variant']->id.'-'.$quantity);
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
     *
     * The cart line is built WHILE the variant is still active, so
     * cart_items.unit_price snapshots the variant's own price (52900) —
     * mirroring the realistic mid-checkout deactivation. Once the variant is
     * inactive, ProductCatalog::price() falls back to the product's base
     * price (49900, see EloquentProductCatalog::price()'s own comment) — a
     * different figure than the cart snapshotted. If the variant were
     * resolved AFTER the price comparison rather than before, that mismatch
     * would surface as PriceChanged ("cena se změnila") instead of the
     * correct InsufficientStock ("varianta již není dostupná") — this test
     * only proves anything because the variant is deactivated after the cart
     * already holds the higher, variant-priced snapshot.
     */
    public function test_a_deactivated_variant_refuses_placement(): void
    {
        $data = $this->shirt();

        $cart = $this->cartWithVariant($data);

        $this->context->runAs($this->tenant, function () use ($data) {
            $data['variant']->update(['active' => false]);
        });

        $this->expectException(InsufficientStock::class);

        $this->place($cart, 'test-deactivated-'.$data['variant']->id);
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

    // --- OrderEditor::edit() must preserve, never corrupt, a line's variant
    // (Task 7 review, must-close CRITICAL) ----------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function editArgs(array $overrides = []): array
    {
        return array_merge([
            'billing' => ['name' => 'Jan Novák', 'street' => 'Dlouhá 1', 'city' => 'Praha', 'zip' => '11000'],
            'shipping' => null,
            'email' => 'zakaznik@example.com',
            'phone' => null,
            'note' => null,
            'actorType' => OrderEvent::ACTOR_ADMIN,
            'actorId' => null,
        ], $overrides);
    }

    /**
     * The admin edit form has no variant picker; it must not need one to
     * avoid corrupting a variant line. Resubmitting the same product/quantity
     * — as address-only edit would — must reprice from the VARIANT (52900),
     * never silently fall back to the product's base price (49900), and must
     * not drop variant_id/variant_label to null.
     */
    public function test_editing_an_order_preserves_the_variant_price_id_and_label(): void
    {
        $data = $this->shirt();
        $order = $this->placeOrderWithVariant($data, quantity: 2);

        $this->context->runAs($this->tenant, function () use ($order, $data) {
            $item = $order->items()->firstOrFail();

            app(OrderEditor::class)->edit(
                $order,
                [['id' => $item->id, 'product_id' => $item->product_id, 'quantity' => 2]],
                ...$this->editArgs(),
            );

            $fresh = $order->fresh()->items()->firstOrFail();

            $this->assertSame(52900, $fresh->unit_price->amount);
            $this->assertSame($data['variant']->id, (int) $fresh->variant_id);
            $this->assertSame('Velikost: M', $fresh->variant_label);
        });
    }

    /**
     * Two lines for the same product on two different variants must never
     * be merged into one, and an edit must move stock on the variant each
     * line actually names — not the product's own (ignored, for a
     * variant-bearing product) stock column.
     */
    public function test_editing_keeps_two_variants_of_the_same_product_distinct(): void
    {
        $data = $this->shirt(stock: 10);

        $variantL = $this->context->runAs($this->tenant, function () use ($data) {
            $size = ProductOption::query()->where('product_id', $data['product']->id)->firstOrFail();
            $valueL = $size->values()->create(['value' => 'L', 'position' => 1]);

            $variantL = ProductVariant::create([
                'product_id' => $data['product']->id,
                'position' => 1,
                'price' => 54900,
                'stock_tracked' => true,
                'stock_qty' => 10,
            ]);
            $variantL->optionValues()->attach($valueL->id);

            return $variantL;
        });

        $cart = $this->context->runAs($this->tenant, function () use ($data, $variantL) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $data['product']->id, 2, $data['variant']->id);
            $carts->addItem($cart, $data['product']->id, 3, $variantL->id);

            return $cart;
        });

        $order = $this->place($cart, 'test-two-variants');

        $this->context->runAs($this->tenant, function () use ($order, $data, $variantL) {
            $items = $order->items()->get();
            $this->assertCount(2, $items);

            $lineM = $items->firstWhere('variant_id', $data['variant']->id);
            $lineL = $items->firstWhere('variant_id', $variantL->id);
            $this->assertNotNull($lineM);
            $this->assertNotNull($lineL);

            // Edit: bump the L line from 3 to 5, leave M at 2 — both lines
            // carry their own `id` through, which is what lets the server
            // tell them apart even though they share product_id.
            app(OrderEditor::class)->edit(
                $order,
                [
                    ['id' => $lineM->id, 'product_id' => $lineM->product_id, 'quantity' => 2],
                    ['id' => $lineL->id, 'product_id' => $lineL->product_id, 'quantity' => 5],
                ],
                ...$this->editArgs(),
            );

            $freshItems = $order->fresh()->items()->get();
            $this->assertCount(2, $freshItems);

            $freshM = $freshItems->firstWhere('variant_id', $data['variant']->id);
            $freshL = $freshItems->firstWhere('variant_id', $variantL->id);

            $this->assertSame(2, $freshM->quantity);
            $this->assertSame(5, $freshL->quantity);
            $this->assertSame(52900, $freshM->unit_price->amount);
            $this->assertSame(54900, $freshL->unit_price->amount);
            $this->assertSame('Velikost: M', $freshM->variant_label);
            $this->assertSame('Velikost: L', $freshL->variant_label);
        });

        $this->context->runAs($this->tenant, function () use ($data, $variantL) {
            // M: 10 - 2 (placed) - 0 (unchanged by the edit) = 8.
            $this->assertSame(8, ProductVariant::query()->whereKey($data['variant']->id)->value('stock_qty'));
            // L: 10 - 3 (placed) - 2 (edit's +2 delta) = 5 — moved on the L
            // variant specifically, never on M's.
            $this->assertSame(5, ProductVariant::query()->whereKey($variantL->id)->value('stock_qty'));
        });
    }

    /**
     * A variant deleted since placement cannot be re-priced — the edit must
     * refuse outright (the same treatment a vanished product already gets),
     * never fall back to the product's base price, and the refusal must
     * leave the order exactly as it was (no partial rewrite).
     */
    public function test_editing_refuses_and_leaves_the_order_untouched_when_a_lines_variant_has_vanished(): void
    {
        $data = $this->shirt();
        $order = $this->placeOrderWithVariant($data, quantity: 2);

        $itemId = $this->context->runAs($this->tenant, function () use ($order, $data) {
            $itemId = $order->items()->firstOrFail()->id;
            ProductVariant::query()->whereKey($data['variant']->id)->delete();

            return $itemId;
        });

        $this->context->runAs($this->tenant, function () use ($order, $data, $itemId) {
            try {
                app(OrderEditor::class)->edit(
                    $order,
                    [['id' => $itemId, 'product_id' => $data['product']->id, 'quantity' => 3]],
                    ...$this->editArgs(),
                );
                $this->fail('Expected InsufficientStock to be thrown.');
            } catch (InsufficientStock) {
                // Expected — asserted below via the untouched order state.
            }
        });

        $this->context->runAs($this->tenant, function () use ($order) {
            $fresh = $order->fresh();
            $this->assertSame(1, $fresh->items()->count());
            $this->assertSame(2, $fresh->items()->firstOrFail()->quantity);
        });
    }
}
