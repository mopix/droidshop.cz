<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Discounts\Contracts\DiscountRedemption as DiscountRedemptionContract;
use App\Core\Discounts\Exceptions\DiscountNoLongerValid;
use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountRedemption;
use Modules\Discounts\Models\DiscountTarget;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Services\OrderEditor;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The discount as the order records it — the last and only binding evaluation.
 *
 * DB-backed against the same MySQL test database the rest of the suite uses,
 * because the properties under test are properties of OrderPlacer's single
 * transaction (the allowance is consumed with the stock, or not at all) and a
 * mock would only restate the implementation.
 */
class OrderDiscountTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['products', 'categories', 'checkout', 'shipping', 'orders', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(int $price, array $attributes = []): Product
    {
        return app(ProductWriter::class)->create([
            'name' => 'Testovací produkt',
            'price' => $price,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
        ]);
    }

    /**
     * A persisted cart with the given lines, snapshotted at the catalogue
     * price so placement never trips the PriceChanged guard.
     *
     * @param  array<int, int>  $lines  productId => quantity
     */
    private function cart(?string $coupon, array $lines): Cart
    {
        $cart = Cart::query()->create([
            'token' => 'tok-'.bin2hex(random_bytes(6)),
            'coupon_code' => $coupon,
        ]);

        foreach ($lines as $productId => $quantity) {
            $product = Product::query()->findOrFail($productId);

            $cart->items()->create([
                'product_id' => $productId,
                'variant_id' => 0,
                'quantity' => $quantity,
                'unit_price' => $product->price,
                'currency' => 'CZK',
            ]);
        }

        return $cart->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function place(Cart $cart, array $overrides = []): Order
    {
        $placed = app(OrderPlacement::class)->place(new PlacementRequest(
            cart: $cart,
            shippingMethodId: $overrides['shippingMethodId'] ?? null,
            paymentMethodId: null,
            email: $overrides['email'] ?? 'kupujici@example.com',
            phone: null,
            billing: [
                'name' => 'Jan Novák',
                'street' => 'Dlouhá 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
            ],
            shipping: null,
            checkoutToken: 'chk-'.bin2hex(random_bytes(6)),
        ));

        return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
    }

    // --- scenarios --------------------------------------------------------

    public function test_a_placed_order_records_the_discount_on_the_order_and_its_lines(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);
            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $order = $this->place($this->cart('SLEVA10', [$product->id => 1]));

            $this->assertSame(10000, $order->discount_total->amount);
            $this->assertSame(90000, $order->items_total->amount);
            $this->assertSame(90000, $order->total->amount);

            $item = $order->items()->firstOrFail();
            $this->assertSame(10000, $item->discount_total->amount);
            $this->assertSame(90000, $item->line_total->amount);
            // The unit price stays what the catalogue charges — the discount
            // is a line-level reduction, not a repriced product.
            $this->assertSame(100000, $item->unit_price->amount);

            $snapshot = $order->discounts()->firstOrFail();
            $this->assertSame('SLEVA10', $snapshot->code);
            $this->assertSame('Sleva 10 %', $snapshot->name);
            $this->assertSame(Discount::TYPE_PERCENT, $snapshot->type);
            $this->assertSame(10000, (int) $snapshot->amount);
            $this->assertFalse($snapshot->free_shipping);
        });
    }

    public function test_the_snapshot_survives_the_discount_being_deleted(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);
            $discount = Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $order = $this->place($this->cart('SLEVA10', [$product->id => 1]));

            $discount->delete();

            $snapshot = $order->discounts()->firstOrFail();
            $this->assertSame('SLEVA10', $snapshot->code);
            $this->assertSame(10000, (int) $snapshot->amount);
        });
    }

    public function test_the_vat_summary_matches_the_charged_total(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);
            Discount::factory()->code('SLEVA10')->percent(100)->create();

            $order = $this->place($this->cart('SLEVA10', [$product->id => 1]));

            $gross = array_sum(array_map(
                fn (array $row): int => $row['base'] + $row['vat'],
                $order->vat_summary,
            ));

            $this->assertSame($order->total->amount, $gross);
        });
    }

    public function test_the_usage_allowance_is_consumed_inside_the_order_transaction(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);
            $discount = Discount::factory()->code('JEDEN')->percent(100)->create(['usage_limit' => 1]);

            $order = $this->place($this->cart('JEDEN', [$product->id => 1]));

            $this->assertSame(1, (int) $discount->fresh()->used_count);
            $this->assertDatabaseHas('discount_redemptions', [
                'discount_id' => $discount->id,
                'order_id' => $order->id,
                'email' => 'kupujici@example.com',
                'amount' => 10000,
                'released_at' => null,
            ]);
        });
    }

    public function test_an_exhausted_coupon_stops_applying_and_leaves_no_row(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);
            $discount = Discount::factory()->code('JEDEN')->percent(100)->create([
                'usage_limit' => 1,
                'used_count' => 1,
            ]);

            // The engine rejects it, so the order is placed at full price —
            // no throw, no discount. The shopper saw the reason on the recap.
            $order = $this->place($this->cart('JEDEN', [$product->id => 1]));

            $this->assertSame(0, $order->discount_total->amount);
            $this->assertSame(100000, $order->total->amount);
            $this->assertSame(0, $order->discounts()->count());
            $this->assertSame(1, (int) $discount->fresh()->used_count);
            $this->assertDatabaseMissing('discount_redemptions', ['order_id' => $order->id]);
        });
    }

    public function test_free_shipping_zeroes_the_delivery_charge_on_the_order(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);
            Discount::factory()->code('DOPRAVA')->freeShipping()->create(['name' => 'Doprava zdarma']);

            $method = ShippingMethod::query()->create([
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr',
                'price' => 9900,
                'currency' => 'CZK',
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'is_active' => true,
            ]);

            $order = $this->place(
                $this->cart('DOPRAVA', [$product->id => 1]),
                ['shippingMethodId' => $method->id],
            );

            $this->assertSame(0, $order->shipping_total->amount);
            $this->assertSame(100000, $order->total->amount);
            $this->assertTrue($order->discounts()->firstOrFail()->free_shipping);
        });
    }

    /**
     * order_items.line_total and orders.items_total are unsigned columns, so a
     * line discounted past its own worth is not merely wrong money — it is a
     * write failure. Two discounts aimed at the same line are the case that
     * would produce one if the allocation were not capacity-aware.
     */
    public function test_no_line_total_goes_negative_when_several_discounts_hit_the_same_line(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $cheap = $this->product(10000, ['name' => 'Levný']);
            $dear = $this->product(100000, ['name' => 'Drahý']);

            // A 100 % coupon on the cheap line, plus an automatic fixed rule
            // of 500 Kč aimed at the very same line: together far more than
            // the line is worth.
            $coupon = Discount::factory()->code('VSE')->percent(1000)->create([
                'name' => 'Vše zdarma',
                'scope' => Discount::SCOPE_PRODUCTS,
            ]);
            $coupon->targets()->create([
                'target_type' => DiscountTarget::TYPE_PRODUCT,
                'target_id' => $cheap->id,
            ]);

            $rule = Discount::factory()->fixed(50000)->create([
                'name' => 'Akce',
                'scope' => Discount::SCOPE_PRODUCTS,
            ]);
            $rule->targets()->create([
                'target_type' => DiscountTarget::TYPE_PRODUCT,
                'target_id' => $cheap->id,
            ]);

            $order = $this->place($this->cart('VSE', [$cheap->id => 1, $dear->id => 1]));

            $items = $order->items()->orderBy('id')->get();

            foreach ($items as $item) {
                $this->assertGreaterThanOrEqual(0, $item->line_total->amount);
                $this->assertLessThanOrEqual($item->unit_price->amount * $item->quantity, $item->discount_total->amount);
            }

            // Nothing came off the line no discount targeted, and the cheap
            // line went to exactly zero — never below it.
            $this->assertSame(0, $items[0]->line_total->amount);
            $this->assertSame(10000, $items[0]->discount_total->amount);
            $this->assertSame(100000, $items[1]->line_total->amount);

            // The one invariant every downstream reader depends on: the order
            // total is the sum of what its lines charge.
            $this->assertSame(
                $items->sum(fn ($item): int => $item->line_total->amount),
                $order->items_total->amount,
            );
            $this->assertSame(10000, $order->discount_total->amount);
        });
    }

    /**
     * The cart and the recap price with `email: null` (CartPricer has no
     * address), so `usage_limit_per_email` and `first_order_only` are the two
     * conditions whose answer can only change at submit. Charging the shopper
     * a total they never saw is not an option: the order is refused with a
     * Czech reason, exactly as a moved price is.
     */
    public function test_a_coupon_the_email_disqualifies_refuses_the_order(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000, ['stock_tracked' => true, 'stock_qty' => 5]);
            Discount::factory()->code('UVITACI')->percent(100)->create(['usage_limit_per_email' => 1]);

            // The address has already used it once.
            $this->place($this->cart('UVITACI', [$product->id => 1]));

            try {
                $this->place($this->cart('UVITACI', [$product->id => 1]));
                $this->fail('A coupon the e-mail disqualifies must refuse the order.');
            } catch (DiscountNoLongerValid $e) {
                $this->assertSame('Slevový kód UVITACI už není platný.', $e->getMessage());
            }

            // Only the first order exists, and only its unit left stock.
            $this->assertSame(1, Order::query()->count());
            $this->assertSame(4, (int) $product->fresh()->stock_qty);
            $this->assertSame(1, DiscountRedemption::query()->count());
        });
    }

    /**
     * A code the recap ALREADY showed as rejected must stay a no-op: turning
     * it into a dead end at submit would punish the shopper for something they
     * were told about two screens ago.
     */
    public function test_a_coupon_rejected_for_a_non_email_reason_still_places_at_full_price(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);
            Discount::factory()->code('PROSLA')->percent(100)->create([
                'ends_at' => now()->subDay(),
            ]);

            $order = $this->place($this->cart('PROSLA', [$product->id => 1]));

            $this->assertSame(100000, $order->total->amount);
            $this->assertSame(0, $order->discount_total->amount);
        });
    }

    // --- admin edit -------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function edit(Order $order, array $lines): Order
    {
        return app(OrderEditor::class)->edit(
            $order,
            $lines,
            $order->billing,
            null,
            $order->email,
            $order->phone,
            null,
            OrderEvent::ACTOR_ADMIN,
            null,
        );
    }

    /**
     * The edit form always posts the whole line list, so an address-only
     * change runs the same delete/recreate as a quantity change. It must not
     * quietly re-price the order at full catalogue price — the customer agreed
     * to the discounted total.
     */
    public function test_an_address_only_edit_leaves_the_discount_and_the_total_alone(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);
            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $order = $this->place($this->cart('SLEVA10', [$product->id => 1]));
            $item = $order->items()->firstOrFail();

            $edited = $this->edit($order, [
                ['id' => $item->id, 'product_id' => $product->id, 'quantity' => 1],
            ]);

            $this->assertSame(90000, $edited->items_total->amount);
            $this->assertSame(10000, $edited->discount_total->amount);
            $this->assertSame(90000, $edited->total->amount);

            $editedItem = $edited->items()->firstOrFail();
            $this->assertSame(90000, $editedItem->line_total->amount);
            $this->assertSame(10000, $editedItem->discount_total->amount);
        });
    }

    public function test_removing_a_line_keeps_the_surviving_lines_discount(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $a = $this->product(100000, ['name' => 'Produkt A']);
            $b = $this->product(100000, ['name' => 'Produkt B']);
            Discount::factory()->code('SLEVA10')->percent(100)->create();

            $order = $this->place($this->cart('SLEVA10', [$a->id => 1, $b->id => 1]));
            $this->assertSame(20000, $order->discount_total->amount);

            $keep = $order->items()->where('product_id', $a->id)->firstOrFail();

            $edited = $this->edit($order, [
                ['id' => $keep->id, 'product_id' => $a->id, 'quantity' => 1],
            ]);

            $survivor = $edited->items()->firstOrFail();
            $this->assertSame(1, $edited->items()->count());
            $this->assertSame(10000, $survivor->discount_total->amount);
            $this->assertSame(90000, $survivor->line_total->amount);

            // The removed line took its own share with it — the invariant
            // orders.discount_total == Σ item discount_total still holds.
            $this->assertSame(10000, $edited->discount_total->amount);
            $this->assertSame(90000, $edited->items_total->amount);
        });
    }

    public function test_a_line_added_by_the_admin_gets_no_discount(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $a = $this->product(100000, ['name' => 'Produkt A']);
            $b = $this->product(50000, ['name' => 'Produkt B']);
            Discount::factory()->code('SLEVA10')->percent(100)->create();

            $order = $this->place($this->cart('SLEVA10', [$a->id => 1]));
            $item = $order->items()->firstOrFail();

            $edited = $this->edit($order, [
                ['id' => $item->id, 'product_id' => $a->id, 'quantity' => 1],
                ['product_id' => $b->id, 'quantity' => 1],
            ]);

            $added = $edited->items()->where('product_id', $b->id)->firstOrFail();

            // The engine is never re-run on an edit, so a new line is priced
            // at the catalogue price and discounted by nothing.
            $this->assertSame(0, $added->discount_total->amount);
            $this->assertSame(50000, $added->line_total->amount);
            $this->assertSame(10000, $edited->discount_total->amount);
            $this->assertSame(140000, $edited->items_total->amount);
        });
    }

    /**
     * The whole reason redeem() runs inside the transaction: an order that
     * cannot take the allowance must not exist, and the stock it already took
     * must come back.
     */
    public function test_a_refused_redemption_rolls_the_order_and_the_stock_back(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000, ['stock_tracked' => true, 'stock_qty' => 5]);
            Discount::factory()->code('SLEVA10')->percent(100)->create();

            $this->app->instance(DiscountRedemptionContract::class, new class implements DiscountRedemptionContract
            {
                public function redeem(int $discountId, int $orderId, string $email, ?int $customerId, Money $amount): void
                {
                    throw DiscountNoLongerValid::forCode('SLEVA10');
                }

                public function release(int $orderId): void {}
            });

            try {
                $this->place($this->cart('SLEVA10', [$product->id => 1]));
                $this->fail('Placement should have been refused.');
            } catch (DiscountNoLongerValid) {
                // expected
            }

            $this->assertSame(0, Order::query()->count());
            $this->assertSame(5, (int) $product->fresh()->stock_qty);
        });
    }
}
