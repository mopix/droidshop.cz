<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentView;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Sequences\SequenceService;
use App\Core\Settings\SettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\InvoiceIssuer;
use Modules\Docs\Services\InvoiceSnapshot;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Issuing an invoice for a placed order — the write side of wave 1.5.
 *
 * DB-backed against the same MySQL test database the suite uses, not a mock:
 * the invariants under test (idempotency on (order, type), gap-free numbering,
 * a full snapshot that never re-reads live data) are properties of the
 * transaction as MySQL runs it.
 */
class InvoiceIssuerTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create([
            'name' => 'Shop One',
            'billing_name' => 'Shop One s.r.o.',
            'billing_ico' => '12345678',
            'billing_dic' => 'CZ12345678',
            'vat_payer' => true,
            'billing_address' => ['street' => 'Hlavní 1', 'city' => 'Praha', 'zip' => '110 00', 'country' => 'CZ'],
        ]);

        $this->activateModule($this->tenant, 'checkout');
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');
        $this->activateModule($this->tenant, 'docs');
    }

    // --- helpers ----------------------------------------------------------

    private function placePaidOrder(): Order
    {
        return $this->context->runAs($this->tenant, function (): Order {
            $product = app(ProductWriter::class)->create([
                'name' => 'Klávesnice Acme',
                'sku' => 'KB-1',
                'price' => 99900,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'status' => Product::STATUS_ACTIVE,
            ]);

            $shipping = ShippingMethod::query()->create([
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr',
                'price' => 9900,
                'currency' => 'CZK',
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'is_active' => true,
            ]);

            $payment = PaymentMethod::query()->create([
                'provider' => PaymentMethod::PROVIDER_COD,
                'name' => 'Dobírka',
                'fee' => 0,
                'currency' => 'CZK',
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'is_active' => true,
            ]);

            /** @var Cart $cart */
            $cart = app(CartRepository::class)->forToken(null);
            app(CartRepository::class)->addItem($cart, $product->id, 2);

            $placed = app(OrderPlacement::class)->place(new PlacementRequest(
                cart: $cart,
                shippingMethodId: $shipping->id,
                paymentMethodId: $payment->id,
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

            $order = Order::query()->where('uuid', $placed->uuid())->firstOrFail();
            $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

            return $order;
        });
    }

    private function issue(string $uuid): DocumentView
    {
        return $this->context->runAs($this->tenant, fn () => app(DocumentIssuer::class)->issue($uuid));
    }

    // --- scenarios --------------------------------------------------------

    public function test_deploy_binds_the_invoice_issuer(): void
    {
        $this->assertInstanceOf(InvoiceIssuer::class, app(DocumentIssuer::class));
    }

    public function test_issue_creates_one_invoice_and_is_idempotent(): void
    {
        $order = $this->placePaidOrder();

        $first = $this->issue($order->uuid);
        $second = $this->issue($order->uuid);

        $this->assertSame($first->documentNumber(), $second->documentNumber());
        $this->assertSame('invoice', $first->documentType());
        $this->assertSame(
            1,
            $this->context->runAs($this->tenant, fn () => Document::query()->where('order_id', $order->id)->count()),
        );
    }

    public function test_the_snapshot_captures_supplier_customer_items_and_totals(): void
    {
        $order = $this->placePaidOrder();

        $doc = $this->context->runAs($this->tenant, function () use ($order) {
            app(DocumentIssuer::class)->issue($order->uuid);

            return Document::query()->where('order_id', $order->id)->firstOrFail();
        });

        // Supplier is the tenant's billing profile at issue time.
        $this->assertSame('Shop One s.r.o.', $doc->supplier['name']);
        $this->assertSame('12345678', $doc->supplier['ico']);
        $this->assertSame('CZ12345678', $doc->supplier['dic']);
        $this->assertTrue($doc->supplier['vat_payer']);

        // Customer carries the order back-reference used by documentOrderUuid().
        $this->assertSame($order->uuid, $doc->customer['order_uuid']);
        $this->assertSame($order->uuid, $doc->documentOrderUuid());
        $this->assertSame('jana@example.cz', $doc->customer['email']);

        // One line, snapshotted in haléře.
        $this->assertCount(1, $doc->items);
        $this->assertSame('Klávesnice Acme', $doc->items[0]['name']);
        $this->assertSame(2, $doc->items[0]['quantity']);
        $this->assertSame(99900, $doc->items[0]['unit_price']);
        $this->assertSame(199800, $doc->items[0]['line_total']);

        // Total and VAT recap come straight from the order (not recomputed).
        $this->assertSame($order->total->amount, $doc->documentTotal()->amount);
        $this->assertSame('CZK', $doc->documentCurrency());
        $this->assertEquals($order->vat_summary, $doc->vat_summary);
    }

    public function test_a_concurrent_collision_recovers_to_the_existing_document(): void
    {
        $order = $this->placePaidOrder();

        // The winner: a normally issued invoice holding the (order, type) key.
        $winner = $this->issue($order->uuid);

        // The loser: an issuer whose first idempotency lookup is forced to miss,
        // so it proceeds to an insert that collides with the winner's row on the
        // (tenant_id, order_id, type) unique index. The catch must re-read and
        // return the winner rather than surface a 500.
        $result = $this->context->runAs($this->tenant, function () use ($order) {
            $issuer = new class(app(ShopModules::class), app(OrderBook::class), app(SequenceService::class), app(SettingsService::class), app(TenantContext::class), app(InvoiceSnapshot::class)) extends InvoiceIssuer
            {
                public int $lookupCalls = 0;

                protected function existingDocument(int $orderId, string $type): ?Document
                {
                    $this->lookupCalls++;

                    if ($this->lookupCalls === 1) {
                        return null;
                    }

                    return parent::existingDocument($orderId, $type);
                }
            };

            return $issuer->issue($order->uuid);
        });

        $this->assertSame($winner->documentNumber(), $result->documentNumber());
        $this->assertSame(
            1,
            $this->context->runAs($this->tenant, fn () => Document::query()->where('order_id', $order->id)->count()),
        );
    }

    public function test_an_unknown_order_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->issue('00000000-0000-0000-0000-000000000000');
    }
}
