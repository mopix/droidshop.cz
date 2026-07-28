<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Discounts\Contracts\DiscountRedemption as DiscountRedemptionContract;
use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderSettlement;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountRedemption;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\OrderEditor;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Task 9: the two places that give stock back — admin storno
 * (OrderEditor::cancel) and a failed/expired online payment
 * (EloquentOrderSettlement::settleFailed) — must also give the discount
 * allowance back, in the SAME transaction, released strictly AFTER the stock
 * (OrderPlacer's binding lock-ordering docblock: products first, discount row
 * last — a release path must acquire the same order or it deadlocks a
 * concurrent placement on a popular coupon).
 */
class OrderDiscountReleaseTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        foreach (['orders', 'products', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function rateId(): int
    {
        return app(TaxRates::class)->default()->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 99900,
            'tax_rate_id' => $this->rateId(),
            'status' => Product::STATUS_ACTIVE,
            'stock_tracked' => true,
            'stock_qty' => 10,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function billing(array $attributes = []): array
    {
        return array_merge([
            'name' => 'Jana Nováková',
            'street' => 'Hlavní 1',
            'city' => 'Praha',
            'zip' => '110 00',
            'country' => 'CZ',
        ], $attributes);
    }

    /**
     * Places an order the same shape OrderPlacer would, decrementing stock
     * exactly as a real checkout would, so the release tests start from a
     * realistic stock position.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrderWithLine(Product $product, int $quantity, string $email, array $attributes = []): Order
    {
        return $this->context->runAs($this->tenant, function () use ($product, $quantity, $email, $attributes) {
            app(ProductCatalog::class)->decrementStock($product->id, $quantity);

            $order = Order::query()->create(array_merge([
                'number' => '2026'.random_int(100000, 999999),
                'checkout_token' => Str::uuid()->toString(),
                'source' => Order::SOURCE_STOREFRONT,
                'email' => $email,
                'billing' => $this->billing(),
                'currency' => 'CZK',
                'items_total' => $product->price->amount * $quantity,
                'shipping_total' => 0,
                'payment_fee' => 0,
                'total' => $product->price->amount * $quantity,
                'fulfillment_status' => Order::FULFILLMENT_NEW,
                'payment_status' => Order::PAYMENT_UNPAID,
                'placed_at' => now(),
            ], $attributes));

            $order->items()->create([
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => $product->price,
                'tax_rate' => $product->catalogTaxRatePercent(),
                'quantity' => $quantity,
                'line_total' => $product->price->times($quantity),
                'currency' => 'CZK',
            ]);

            return $order;
        });
    }

    private function stockOf(Product $product): int
    {
        return $this->context->runAs(
            $this->tenant,
            fn () => Product::query()->findOrFail($product->id)->stock_qty,
        );
    }

    /**
     * Records a redemption exactly as EloquentDiscountRedemption::redeem()
     * would have, without going through a real checkout — the release side is
     * under test here, not the consuming side (already covered by
     * DiscountRedemptionTest and OrderDiscountTest).
     */
    private function redeem(Discount $discount, Order $order, string $email, int $amount = 10000): void
    {
        $this->context->runAs($this->tenant, function () use ($discount, $order, $email, $amount): void {
            DiscountRedemption::query()->create([
                'discount_id' => $discount->id,
                'order_id' => $order->id,
                'email' => $email,
                'amount' => $amount,
            ]);

            $discount->increment('used_count');
        });
    }

    // --- admin storno --------------------------------------------------

    public function test_cancelling_an_order_gives_the_allowance_back(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1, 'kupujici@example.com');

        $discount = $this->context->runAs($this->tenant, fn () => Discount::factory()->code('JEDEN')->percent(100)->create([
            'usage_limit' => 1,
        ]));

        $this->redeem($discount, $order, 'kupujici@example.com');

        $this->context->runAs($this->tenant, function () use ($order): void {
            app(OrderEditor::class)->cancel($order, 'Zákazník si to rozmyslel.', returnStock: true, sendEmail: false, actorType: 'admin', actorId: null);
        });

        $this->context->runAs($this->tenant, function () use ($discount, $order): void {
            $this->assertSame(0, (int) $discount->fresh()->used_count);
            $this->assertNotNull(DiscountRedemption::query()->where('order_id', $order->id)->firstOrFail()->released_at);
        });
    }

    public function test_cancelling_an_order_with_no_discount_is_unaffected(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1, 'kupujici@example.com');

        $this->context->runAs($this->tenant, function () use ($order): void {
            $cancelled = app(OrderEditor::class)->cancel($order, 'Bez slevy.', returnStock: true, sendEmail: false, actorType: 'admin', actorId: null);

            $this->assertSame(Order::FULFILLMENT_CANCELLED, $cancelled->fulfillment_status);
            $this->assertSame(0, DiscountRedemption::query()->count());
        });
    }

    // --- gateway failure / expiry ---------------------------------------

    public function test_a_failed_payment_gives_the_allowance_back(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1, 'kupujici@example.com');

        $discount = $this->context->runAs($this->tenant, fn () => Discount::factory()->code('JEDEN')->percent(100)->create([
            'usage_limit' => 1,
        ]));

        $this->redeem($discount, $order, 'kupujici@example.com');

        $this->context->runAs($this->tenant, function () use ($order): void {
            $moved = app(OrderSettlement::class)->settleFailed($order->uuid, returnStock: true);
            $this->assertTrue($moved);
        });

        $this->context->runAs($this->tenant, function () use ($discount, $order): void {
            $this->assertSame(0, (int) $discount->fresh()->used_count);
            $this->assertNotNull(DiscountRedemption::query()->where('order_id', $order->id)->firstOrFail()->released_at);
        });
    }

    public function test_settling_an_already_settled_order_does_not_release_twice(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1, 'kupujici@example.com');

        $discount = $this->context->runAs($this->tenant, fn () => Discount::factory()->code('JEDEN')->percent(100)->create([
            'usage_limit' => 1,
        ]));

        $this->redeem($discount, $order, 'kupujici@example.com');

        $this->context->runAs($this->tenant, function () use ($order): void {
            $this->assertTrue(app(OrderSettlement::class)->settleFailed($order->uuid, returnStock: true));
            // Already failed: settleFailed's own unpaid-only guard refuses a
            // second call before it ever reaches the release — this proves
            // that guard, not release()'s own idempotence, is what protects
            // the counter here.
            $this->assertFalse(app(OrderSettlement::class)->settleFailed($order->uuid, returnStock: true));
        });

        $this->context->runAs($this->tenant, fn () => $this->assertSame(0, (int) $discount->fresh()->used_count));
    }

    // --- both eligibility gates really reopen ----------------------------

    public function test_a_released_coupon_can_be_used_again_by_the_same_email(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1, 'jana@example.cz');

        $discount = $this->context->runAs($this->tenant, fn () => Discount::factory()->code('UVITACI')->percent(100)->create([
            'usage_limit' => 1,
            'usage_limit_per_email' => 1,
        ]));

        $this->redeem($discount, $order, 'jana@example.cz');

        $this->context->runAs($this->tenant, function () use ($order): void {
            app(OrderEditor::class)->cancel($order, 'Vrátit slevu.', returnStock: true, sendEmail: false, actorType: 'admin', actorId: null);
        });

        // Both gates reopened: usage_limit (used_count back at 0) and the
        // per-email limit (the only row for this address is now released).
        $this->context->runAs($this->tenant, function () use ($discount): void {
            app(DiscountRedemptionContract::class)->redeem(
                $discount->id,
                9999,
                'jana@example.cz',
                null,
                new Money(10000, 'CZK'),
            );
        });

        $this->context->runAs($this->tenant, function () use ($discount): void {
            $this->assertSame(1, (int) $discount->fresh()->used_count);
            $this->assertSame(1, DiscountRedemption::query()->whereNull('released_at')->count());
        });
    }

    // --- direct release() idempotence at the contract level --------------

    public function test_releasing_twice_directly_does_not_double_decrement(): void
    {
        $product = $this->makeProduct();
        $order = $this->makeOrderWithLine($product, 1, 'kupujici@example.com');

        $discount = $this->context->runAs($this->tenant, fn () => Discount::factory()->code('JEDEN')->percent(100)->create());

        $this->redeem($discount, $order, 'kupujici@example.com');

        $this->context->runAs($this->tenant, function () use ($order): void {
            $redemptions = app(DiscountRedemptionContract::class);
            $redemptions->release($order->id);
            $redemptions->release($order->id);
        });

        $this->context->runAs($this->tenant, fn () => $this->assertSame(0, (int) $discount->fresh()->used_count));
    }
}
