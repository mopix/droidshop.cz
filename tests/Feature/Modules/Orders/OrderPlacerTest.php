<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\Contracts\CartShape;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\Exceptions\OrderPlacementUnavailable;
use App\Core\Orders\Exceptions\PriceChanged;
use App\Core\Orders\NullOrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Sequences\SequenceService;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOptions;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Models\OrderItem;
use Modules\Orders\Services\OrderPlacer;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The order-submission transaction — the correctness core of checkout.
 *
 * Every test is DB-backed against the same MySQL test database the suite
 * uses, not a mock: the invariants under test (idempotency on
 * (cart, checkout_token), stock rollback, catalog-only pricing) are
 * properties of the transaction as MySQL runs it, and a mock would only
 * re-state the implementation rather than prove it.
 */
class OrderPlacerTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        // checkout pulls products in as a dependency; orders and shipping are
        // switched on explicitly so placement, numbering and method lookup
        // all run under an active-module tenant.
        $this->activateModule($this->tenant, 'checkout');
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');
    }

    // --- helpers ----------------------------------------------------------

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
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeShipping(array $attributes = []): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::query()->create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'currency' => 'CZK',
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePayment(array $attributes = []): PaymentMethod
    {
        return $this->context->runAs($this->tenant, fn () => PaymentMethod::query()->create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'currency' => 'CZK',
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
            ...$attributes,
        ]));
    }

    /**
     * Builds a real, persisted cart with the given lines through the same
     * repository the storefront uses — so cart_items.unit_price is snapshotted
     * exactly the way a genuine checkout would have snapshotted it.
     *
     * @param  array<int, int>  $lines  productId => quantity
     */
    private function makeCart(array $lines): Cart
    {
        return $this->context->runAs($this->tenant, function () use ($lines) {
            /** @var Cart $cart */
            $cart = app(CartRepository::class)->forToken(null);

            foreach ($lines as $productId => $quantity) {
                app(CartRepository::class)->addItem($cart, $productId, $quantity);
            }

            return $cart;
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function request(CartShape $cart, array $overrides = []): PlacementRequest
    {
        return new PlacementRequest(
            cart: $cart,
            shippingMethodId: $overrides['shippingMethodId'] ?? null,
            paymentMethodId: $overrides['paymentMethodId'] ?? null,
            email: $overrides['email'] ?? 'jana@example.cz',
            phone: $overrides['phone'] ?? '+420777123456',
            billing: $overrides['billing'] ?? [
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
            ],
            shipping: $overrides['shipping'] ?? null,
            checkoutToken: $overrides['checkoutToken'] ?? 'tok-'.bin2hex(random_bytes(8)),
            customerId: $overrides['customerId'] ?? null,
            source: $overrides['source'] ?? 'storefront',
            note: $overrides['note'] ?? null,
        );
    }

    private function place(PlacementRequest $request)
    {
        return $this->context->runAs($this->tenant, fn () => app(OrderPlacement::class)->place($request));
    }

    // --- scenarios --------------------------------------------------------

    /**
     * Scenario 1 — the happy path: a two-line cart becomes an order whose
     * line snapshots, totals and VAT recap are all computed server-side.
     */
    public function test_it_places_an_order_with_server_computed_line_snapshots_totals_and_vat(): void
    {
        $a = $this->makeProduct(['name' => 'Produkt A', 'sku' => 'A-1', 'price' => 99900]);
        $b = $this->makeProduct(['name' => 'Produkt B', 'sku' => 'B-1', 'price' => 50000]);
        $shipping = $this->makeShipping(['price' => 9900, 'free_from' => null]);
        $payment = $this->makePayment(['fee' => 0]);

        $cart = $this->makeCart([$a->id => 1, $b->id => 2]);

        $placed = $this->place($this->request($cart, [
            'shippingMethodId' => $shipping->id,
            'paymentMethodId' => $payment->id,
        ]));

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->firstOrFail());

        // One order, two line items.
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
        $items = $this->context->runAs($this->tenant, fn () => $order->items()->orderBy('id')->get());
        $this->assertCount(2, $items);

        // Line A snapshot.
        $this->assertSame('Produkt A', $items[0]->name);
        $this->assertSame('A-1', $items[0]->sku);
        $this->assertSame(99900, $items[0]->unit_price->amount);
        $this->assertSame('21.00', (string) $items[0]->tax_rate);
        $this->assertSame(1, $items[0]->quantity);
        $this->assertSame(99900, $items[0]->line_total->amount);

        // Line B snapshot — line_total is unit_price * quantity, server-side.
        $this->assertSame('Produkt B', $items[1]->name);
        $this->assertSame(50000, $items[1]->unit_price->amount);
        $this->assertSame(2, $items[1]->quantity);
        $this->assertSame(100000, $items[1]->line_total->amount);

        // Totals: items 199900, shipping 9900 (below no threshold), fee 0.
        $this->assertSame(199900, $order->items_total->amount);
        $this->assertSame(9900, $order->shipping_total->amount);
        $this->assertSame(0, $order->payment_fee->amount);
        $this->assertSame(209800, $order->total->amount);
        $this->assertSame('CZK', $order->total->currency);

        // VAT recap: one 21 % group over gross 209800; net 173388, VAT 36412
        // (computed on the summed gross per rate, not per line).
        $this->assertEquals(
            [['rate' => 21.0, 'base' => 173388, 'vat' => 36412]],
            $order->vat_summary,
        );

        // Placement side effects.
        $this->assertNotNull($order->placed_at);
        $this->assertNotSame('', $order->number);

        $this->assertSame(
            1,
            $this->context->runAs($this->tenant, fn () => OrderEvent::query()
                ->where('order_id', $order->id)
                ->where('type', 'created')
                ->count()),
        );

        // The cart is marked converted.
        $freshCart = $this->context->runAs($this->tenant, fn () => Cart::query()->findOrFail($cart->id));
        $this->assertNotNull($freshCart->converted_at);

        // The confirmation shape hands back the same identifiers.
        $this->assertSame($order->uuid, $placed->uuid());
        $this->assertSame($order->number, $placed->number());
        $this->assertSame(209800, $placed->total()->amount);
    }

    /**
     * Scenario 2 (AK 2) — idempotency: a resubmit with the same
     * (cart, checkout_token) returns the order already placed, never a second.
     */
    public function test_a_resubmit_with_the_same_cart_and_token_returns_the_same_order(): void
    {
        $product = $this->makeProduct(['price' => 12300]);
        $cart = $this->makeCart([$product->id => 1]);

        $token = 'fixed-token';
        $first = $this->place($this->request($cart, ['checkoutToken' => $token]));
        $second = $this->place($this->request($cart, ['checkoutToken' => $token]));

        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
        $this->assertSame($first->uuid(), $second->uuid());
        $this->assertSame($first->number(), $second->number());
        // No duplicate line items were written on the retry.
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => OrderItem::query()->count()));
    }

    /**
     * Scenario 2b (AK 2, concurrent half) — two submits that both pass the
     * idempotency SELECT before either commits: the losing Order::create()
     * hits order_idem_unique, and place() must recover to the winning order
     * instead of surfacing the duplicate-key error as a 500.
     *
     * A real two-thread race is not deterministic under single-threaded
     * PHPUnit, so the collision is forced instead of raced: a first order is
     * placed normally, then a placer subclass whose in-transaction idempotency
     * lookup is stubbed to miss re-submits the same (cart, token). Its
     * Order::create() therefore collides with the already-committed row — the
     * exact state a losing concurrent request would be in — driving the
     * UniqueConstraintViolationException catch path. See the report's honesty
     * note for what this does and does not cover versus a genuine race.
     */
    public function test_a_concurrent_collision_recovers_to_the_existing_order(): void
    {
        $product = $this->makeProduct(['price' => 12300]);
        $cart = $this->makeCart([$product->id => 1]);
        $token = 'race-token';

        // The winner: a normally placed order holding the idempotency key.
        $winner = $this->place($this->request($cart, ['checkoutToken' => $token]));

        // The loser: a placer whose first (in-transaction) idempotency lookup
        // is forced to miss, so it proceeds to an insert that collides with the
        // winner's row. The catch must re-read and return the winner.
        $result = $this->context->runAs($this->tenant, function () use ($cart, $token) {
            $placer = new class(app(ShopModules::class), app(ProductCatalog::class), app(ShippingOptions::class), app(PaymentOptions::class), app(SequenceService::class), app(TaxRates::class)) extends OrderPlacer
            {
                public int $lookupCalls = 0;

                protected function existingOrder(PlacementRequest $request): ?Order
                {
                    $this->lookupCalls++;

                    // First call is the in-transaction idempotency SELECT:
                    // pretend the winner is not yet visible (the concurrent
                    // race window). Later calls — the catch's recovery read —
                    // see the truth.
                    if ($this->lookupCalls === 1) {
                        return null;
                    }

                    return parent::existingOrder($request);
                }
            };

            return $placer->place($this->request($cart, ['checkoutToken' => $token]));
        });

        // Exactly one order exists, and the loser was handed the winner's.
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
        $this->assertSame($winner->uuid(), $result->uuid());
        $this->assertSame($winner->number(), $result->number());
        // The collided attempt wrote no extra lines either.
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => OrderItem::query()->count()));
    }

    /**
     * Scenario 3 (AK 3) — the last unit: a second checkout on a depleted
     * product fails with InsufficientStock and writes nothing.
     *
     * See the report for what this proves and what it delegates: PHPUnit runs
     * single-threaded under a RefreshDatabase transaction, so the two
     * checkouts run sequentially rather than truly in parallel. What the test
     * proves is that OrderPlacer propagates decrementStock's InsufficientStock
     * and rolls the whole transaction back — no second order, no orphan lines,
     * stock left at 0. The atomicity of the decrement itself (two connections
     * racing on the last unit) is the single conditional UPDATE inside
     * EloquentProductCatalog::decrementStock, tested there.
     */
    public function test_the_second_checkout_on_the_last_unit_fails_and_rolls_back(): void
    {
        $product = $this->makeProduct([
            'price' => 40000,
            'stock_tracked' => true,
            'stock_qty' => 1,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
        ]);

        $cartOne = $this->makeCart([$product->id => 1]);
        $cartTwo = $this->makeCart([$product->id => 1]);

        // First checkout wins the unit.
        $this->place($this->request($cartOne));

        $stockAfterFirst = $this->context->runAs($this->tenant, fn () => Product::query()->findOrFail($product->id)->stock_qty);
        $this->assertSame(0, $stockAfterFirst);

        // Second checkout finds nothing left.
        try {
            $this->place($this->request($cartTwo));
            $this->fail('Expected InsufficientStock on the second checkout.');
        } catch (InsufficientStock $e) {
            // expected
        }

        // Exactly one order, its one line, its one event — the failed attempt
        // left no partial write.
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => OrderItem::query()->count()));
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => OrderEvent::query()->count()));

        // No order was created against the losing cart.
        $this->assertSame(
            0,
            $this->context->runAs($this->tenant, fn () => Order::query()->where('cart_id', $cartTwo->id)->count()),
        );

        // Stock ends at 0 — the winner's decrement stuck, the loser's rolled back.
        $stockFinal = $this->context->runAs($this->tenant, fn () => Product::query()->findOrFail($product->id)->stock_qty);
        $this->assertSame(0, $stockFinal);
    }

    /**
     * Scenario 4 (AK 4) — price integrity: a cart line whose snapshot no
     * longer matches the catalogue price is refused, no order created.
     */
    public function test_a_moved_price_since_the_line_was_added_is_refused(): void
    {
        $product = $this->makeProduct(['price' => 50000]);
        $cart = $this->makeCart([$product->id => 1]);

        // The shop owner changes the price after the item was in the cart.
        $this->context->runAs($this->tenant, fn () => Product::query()->whereKey($product->id)->update(['price' => 55000]));

        try {
            $this->place($this->request($cart));
            $this->fail('Expected PriceChanged when the catalogue price moved.');
        } catch (PriceChanged $e) {
            // The exception carries both figures so the checkout controller can
            // render "cena se změnila z 500 na 550 Kč" (AK 4): old = the cart
            // snapshot the shopper agreed to, new = the catalogue's price now.
            $this->assertSame($product->id, $e->productId);
            $this->assertSame(50000, $e->oldPrice->amount);
            $this->assertSame('CZK', $e->oldPrice->currency);
            $this->assertSame(55000, $e->newPrice->amount);
            $this->assertSame('CZK', $e->newPrice->currency);
        }

        // Nothing was written: not the order, not its lines, not its events.
        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => OrderItem::query()->count()));
        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => OrderEvent::query()->count()));
    }

    /**
     * Scenario 5 (AK 5) — the price authority is the catalogue: figures a
     * client might smuggle in are ignored, the total comes from
     * ProductCatalog::price().
     */
    public function test_client_supplied_amounts_are_ignored_and_the_total_comes_from_the_catalogue(): void
    {
        $product = $this->makeProduct(['price' => 12300]);
        $cart = $this->makeCart([$product->id => 1]);

        // A hostile client stuffs a bogus total into the free-form billing bag.
        $placed = $this->place($this->request($cart, [
            'billing' => [
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
                'total' => 1,
                'unit_price' => 1,
            ],
        ]));

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->firstOrFail());

        // The catalogue's 12300 wins over the smuggled 1.
        $this->assertSame(12300, $order->items_total->amount);
        $this->assertSame(12300, $order->total->amount);
        $this->assertSame(12300, $placed->total()->amount);

        $catalogPrice = $this->context->runAs($this->tenant, fn () => app(ProductCatalog::class)->price($product->id));
        $this->assertSame($catalogPrice->amount, $order->total->amount);
    }

    /**
     * Scenario 6 — free shipping: a cart at or above the method's threshold
     * ships for nothing, decided server-side from items_total.
     */
    public function test_shipping_is_free_when_the_items_total_reaches_the_threshold(): void
    {
        $product = $this->makeProduct(['price' => 60000]);
        $shipping = $this->makeShipping(['price' => 9900, 'free_from' => 100000]);
        $payment = $this->makePayment(['fee' => 0]);

        // 2 * 60000 = 120000 >= 100000 threshold.
        $cart = $this->makeCart([$product->id => 2]);

        $this->place($this->request($cart, [
            'shippingMethodId' => $shipping->id,
            'paymentMethodId' => $payment->id,
        ]));

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->firstOrFail());

        $this->assertSame(120000, $order->items_total->amount);
        $this->assertSame(0, $order->shipping_total->amount);
        $this->assertSame(120000, $order->total->amount);
    }

    /**
     * Scenario 7 — the module switched off: the kernel's null binding refuses
     * to place rather than pretend to.
     */
    public function test_the_null_binding_refuses_to_place_an_order(): void
    {
        $cart = $this->makeCart([$this->makeProduct()->id => 1]);
        $request = $this->request($cart);

        $this->expectException(OrderPlacementUnavailable::class);

        (new NullOrderPlacement)->place($request);
    }

    /**
     * Scenario 8 (AK 6) — tenant isolation: an order is reachable by uuid
     * only inside the tenant that placed it.
     */
    public function test_an_order_is_only_findable_within_its_own_tenant(): void
    {
        $product = $this->makeProduct(['price' => 12300]);
        $cart = $this->makeCart([$product->id => 1]);

        $placed = $this->place($this->request($cart));

        // A second tenant, with the orders module equally active.
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'orders');

        $foundByOwner = $this->context->runAs($this->tenant, fn () => app(OrderPlacement::class)->find($placed->uuid()));
        $foundByStranger = $this->context->runAs($other, fn () => app(OrderPlacement::class)->find($placed->uuid()));

        $this->assertNotNull($foundByOwner);
        $this->assertSame($placed->uuid(), $foundByOwner->orderUuid());
        $this->assertNull($foundByStranger);
    }
}
