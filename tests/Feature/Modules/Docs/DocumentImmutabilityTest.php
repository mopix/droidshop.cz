<?php

namespace Tests\Feature\Modules\Docs;

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
use Modules\Products\Services\ProductWriter;
use RuntimeException;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * An issued document is a legal record (spec §16.6): once written it may only
 * gain its generated PDF path and a sent timestamp, and may never be deleted.
 * The guard lives on the model so no controller path can mutate the books.
 */
class DocumentImmutabilityTest extends TestCase
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

        $this->activateModule($this->tenant, 'checkout');
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');
        $this->activateModule($this->tenant, 'docs');
    }

    private function issueInvoice(): Document
    {
        return $this->context->runAs($this->tenant, function (): Document {
            $product = app(ProductWriter::class)->create([
                'name' => 'Klávesnice Acme',
                'sku' => 'KB-1',
                'price' => 99900,
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
                phone: null,
                billing: ['name' => 'Jana Nováková', 'street' => 'Hlavní 1', 'city' => 'Praha', 'zip' => '110 00', 'country' => 'CZ'],
                shipping: null,
                checkoutToken: 'tok-'.bin2hex(random_bytes(8)),
                customerId: null,
                source: 'storefront',
                note: null,
            ));

            app(DocumentIssuer::class)->issue($placed->uuid());

            $order = Order::query()->where('uuid', $placed->uuid())->firstOrFail();

            return Document::query()->where('order_id', $order->id)->firstOrFail();
        });
    }

    public function test_an_issued_document_cannot_be_updated(): void
    {
        $doc = $this->issueInvoice();

        $this->expectException(RuntimeException::class);

        $this->context->runAs($this->tenant, fn () => $doc->update(['total' => 1]));
    }

    public function test_an_issued_document_cannot_be_deleted(): void
    {
        $doc = $this->issueInvoice();

        $this->expectException(RuntimeException::class);

        $this->context->runAs($this->tenant, fn () => $doc->delete());
    }

    public function test_pdf_path_and_sent_at_remain_writable(): void
    {
        $doc = $this->issueInvoice();

        $this->context->runAs($this->tenant, fn () => $doc->update([
            'pdf_path' => 'tenants/1/invoices/x.pdf',
            'sent_at' => now(),
        ]));

        $fresh = $this->context->runAs($this->tenant, fn () => $doc->fresh());
        $this->assertSame('tenants/1/invoices/x.pdf', $fresh->pdf_path);
        $this->assertNotNull($fresh->sent_at);
    }
}
