<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountTarget;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Services\OrderEditor;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The invoice's discount note (rozhodnutí 2026-07-28): the item lines already
 * carry discounted amounts, so a document never negates or re-derives money
 * from a discount — it only names, informationally, what fired. DB-backed
 * against the real checkout->place() flow (like OrderDiscountTest) rather
 * than hand-building an order, because the property under test is that
 * InvoiceIssuer reads live order state through OrderView, not a stale
 * order_discounts snapshot.
 */
class InvoiceDiscountTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create([
            'name' => 'Shop One',
            'billing_name' => 'Shop One s.r.o.',
            'billing_ico' => '12345678',
            'billing_dic' => 'CZ12345678',
            'vat_payer' => true,
            'billing_address' => ['street' => 'Hlavní 1', 'city' => 'Praha', 'zip' => '110 00', 'country' => 'CZ'],
        ]);

        foreach (['checkout', 'shipping', 'orders', 'docs', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    // --- helpers, mirroring tests/Feature/Modules/Orders/OrderDiscountTest ---

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

    private function place(Cart $cart): Order
    {
        $placed = app(OrderPlacement::class)->place(new PlacementRequest(
            cart: $cart,
            shippingMethodId: null,
            paymentMethodId: null,
            email: 'kupujici@example.com',
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

    private function issueInvoice(Order $order): Document
    {
        app(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_INVOICE);

        return Document::query()
            ->where('order_id', $order->id)
            ->where('type', Document::TYPE_INVOICE)
            ->firstOrFail();
    }

    // --- scenarios ----------------------------------------------------------

    public function test_the_invoice_carries_the_discount_note_and_the_reduced_total(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);
            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $order = $this->place($this->cart('SLEVA10', [$product->id => 1]));
            $this->assertSame(10000, $order->discount_total->amount);
            $this->assertSame(90000, $order->total->amount);

            $document = $this->issueInvoice($order);

            $this->assertSame(90000, $document->total->amount);
            $this->assertStringContainsString('SLEVA10', $document->discount_note);
            $this->assertSame(10000, $document->discount_total->amount);
        });
    }

    public function test_an_order_with_no_discount_gets_no_note_and_a_zero_discount_total(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $product = $this->product(100000);

            $document = $this->issueInvoice($this->place($this->cart(null, [$product->id => 1])));

            $this->assertNull($document->discount_note);
            $this->assertSame(0, $document->discount_total->amount);
        });
    }

    /**
     * OrderEditor preserves each surviving line's own discount share and
     * re-derives orders.discount_total, but never touches order_discounts —
     * so once an admin edit removes every discounted line, the live total
     * (which the invoice must read) disagrees with the still-nonzero amount
     * sitting in the order_discounts snapshot row (rozhodnutí 2026-07-28,
     * task 11 correction). The invoice, issued after the edit, must reflect
     * reality: no note, because nothing on the order is discounted anymore.
     */
    public function test_an_edit_that_drops_the_discounted_line_leaves_the_invoice_with_no_note(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $discounted = $this->product(100000, ['name' => 'Se slevou']);
            $plain = $this->product(50000, ['name' => 'Bez slevy']);
            $discount = Discount::factory()->code('SLEVA10')->percent(100)->create([
                'name' => 'Sleva 10 %',
                'scope' => Discount::SCOPE_PRODUCTS,
            ]);
            $discount->targets()->create([
                'target_type' => DiscountTarget::TYPE_PRODUCT,
                'target_id' => $discounted->id,
            ]);

            $order = $this->place($this->cart('SLEVA10', [
                $discounted->id => 1,
                $plain->id => 1,
            ]));
            $this->assertSame(10000, $order->discount_total->amount);

            // Stale but true: the discount really did fire on this order.
            $this->assertSame(1, $order->discounts()->count());
            $this->assertSame(10000, (int) $order->discounts()->firstOrFail()->amount);

            $keep = $order->items()->where('product_id', $plain->id)->firstOrFail();

            $edited = app(OrderEditor::class)->edit(
                $order,
                [['id' => $keep->id, 'product_id' => $plain->id, 'quantity' => 1]],
                $order->billing,
                null,
                $order->email,
                $order->phone,
                null,
                OrderEvent::ACTOR_ADMIN,
                null,
            );

            $this->assertSame(0, $edited->discount_total->amount);
            // The stale snapshot row is still sitting there, untouched.
            $this->assertSame(1, $edited->discounts()->count());

            $document = $this->issueInvoice($edited);

            $this->assertNull($document->discount_note);
            $this->assertSame(0, $document->discount_total->amount);
            $this->assertSame(50000, $document->total->amount);
        });
    }
}
