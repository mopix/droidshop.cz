<?php

namespace Tests\Feature\Modules\Orders;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAddon;
use Modules\Products\Models\ProductAddonGroup;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * An accessory on the order and on the money (wave 4.2, task C3).
 *
 * The point of the whole design is here: the addon is a line of its own, with
 * its own rate, and the order's total is the sum of what the customer saw.
 */
class OrderAddonTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private Product $product;

    private ProductAddon $frame;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        foreach (['products', 'checkout', 'orders'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->context->runAs($this->tenant, function (): void {
            $this->product = app(ProductWriter::class)->create([
                'name' => 'Obraz',
                'price' => 42900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'stock_tracked' => true,
                'stock_qty' => 5,
            ]);

            $group = ProductAddonGroup::create([
                'product_id' => $this->product->id,
                'label' => 'Dekorativní rám',
                'required' => false,
                'position' => 0,
            ]);

            $this->frame = ProductAddon::create([
                'group_id' => $group->id,
                'label' => 'Rám – dub',
                'price' => 26900,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'position' => 0,
            ]);
        });
    }

    /**
     * Places the order and returns the stored row.
     *
     * OrderPlacement answers with the kernel's PlacedOrder view; the assertions
     * here are about what was written, so the row is read back by uuid.
     */
    private function placeWithAddon(int $quantity = 1): Order
    {
        return $this->context->runAs($this->tenant, function () use ($quantity): Order {
            /** @var Cart $cart */
            $cart = app(CartRepository::class)->forToken(null);
            app(CartRepository::class)->addItem($cart, $this->product->id, $quantity, null, [$this->frame->id]);

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
                note: null,
            ));

            return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
        });
    }

    public function test_the_addon_is_a_line_of_its_own_pointing_at_its_product(): void
    {
        $order = $this->placeWithAddon();

        $items = $this->context->runAs($this->tenant, fn () => $order->fresh()->items);

        $this->assertCount(2, $items);

        $parent = $items->firstWhere('parent_item_id', null);
        $addon = $items->first(fn ($item) => $item->parent_item_id !== null);

        $this->assertSame('Obraz', $parent->name);
        $this->assertSame('Rám – dub', $addon->name);
        $this->assertSame($parent->id, (int) $addon->parent_item_id);
        $this->assertSame($this->frame->id, (int) $addon->addon_id);
    }

    public function test_the_total_is_the_sum_the_customer_saw(): void
    {
        // 429 + 269 = 698 Kč. If the addon were folded into the product's
        // price, or dropped, this is the number that would move.
        $order = $this->placeWithAddon();

        $this->assertSame(69_800, $order->items_total->amount);
        $this->assertSame(69_800, $order->total->amount);
    }

    public function test_the_addon_quantity_follows_the_product(): void
    {
        $order = $this->placeWithAddon(3);

        $items = $this->context->runAs($this->tenant, fn () => $order->fresh()->items);

        foreach ($items as $item) {
            $this->assertSame(3, $item->quantity);
        }

        $this->assertSame(3 * (42_900 + 26_900), $order->items_total->amount);
    }

    public function test_an_accessory_does_not_take_stock_from_its_product(): void
    {
        // The picture leaves the shelf once, not twice because it had a frame.
        $this->placeWithAddon(2);

        $this->assertSame(3, $this->context->runAs(
            $this->tenant,
            fn () => Product::query()->findOrFail($this->product->id)->stock_qty,
        ));
    }

    public function test_the_addon_carries_its_own_rate_onto_the_line(): void
    {
        $order = $this->placeWithAddon();

        $addon = $this->context->runAs(
            $this->tenant,
            fn () => $order->fresh()->items->first(fn ($item) => $item->parent_item_id !== null),
        );

        $this->assertSame(
            $this->context->runAs($this->tenant, fn () => $this->frame->taxRate->percent()),
            (float) $addon->tax_rate,
        );
    }

    public function test_the_invoice_shows_the_addon_as_its_own_line(): void
    {
        // The reason the whole design is shaped this way: a tax document has
        // to name what was sold and at which rate, and an accessory folded
        // into the product's price is neither named nor taxed on its own.
        $this->activateModule($this->tenant, 'docs');

        $order = $this->placeWithAddon();

        // issue() returns the kernel's view; the lines are read off the stored
        // document, which is the thing the tax office would see.
        $document = $this->context->runAs($this->tenant, function () use ($order) {
            app(DocumentIssuer::class)->issue($order->uuid);

            return Document::query()->where('order_id', $order->id)->firstOrFail();
        });

        $names = collect($document->items)->pluck('name');

        $this->assertTrue($names->contains('Obraz'));
        $this->assertTrue($names->contains('Rám – dub'));

        $line = collect($document->items)->firstWhere('name', 'Rám – dub');

        $this->assertSame(26_900, $line['unit_price']);
    }
}
