<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Storage\FileStorage;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\Checkout\Models\Cart;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

/**
 * Wave 1.5 Task 8 — a signed-in customer downloading their own invoice from
 * the storefront account. Mirrors DocumentAdminTest's download coverage, but
 * gated on auth:customer + OrderBook::findForCustomer() ownership instead of
 * the tenant.member admin gate.
 */
class CustomerInvoiceDownloadTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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

        foreach (['storefront', 'customers', 'checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * Places and pays an order for the given customer, then issues its
     * invoice, returning the document's public number.
     */
    private function issueInvoiceFor(Tenant $tenant, ?int $customerId): string
    {
        return $this->context->runAs($tenant, function () use ($customerId): string {
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
            app(CartRepository::class)->addItem($cart, $product->id, 1);

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
                customerId: $customerId,
                source: 'storefront',
                note: null,
            ));

            $order = Order::query()->where('uuid', $placed->uuid())->firstOrFail();
            $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

            return app(DocumentIssuer::class)->issue($order->uuid)->documentNumber();
        });
    }

    /**
     * Places and pays an order for the given customer, issues its invoice,
     * then cancels the order and issues a credit note against that same
     * invoice. With empty default prefixes, the invoice series and the
     * credit note series both format their first document of the year as
     * "{YYYY}0001" (wave 1.6: `documents` unique per (tenant, type, number),
     * not (tenant, number) alone) — so the two numbers are expected to
     * collide here.
     *
     * @return array{invoice: string, credit_note: string}
     */
    private function issueInvoiceAndCreditNoteFor(Tenant $tenant, ?int $customerId): array
    {
        return $this->context->runAs($tenant, function () use ($customerId): array {
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
            app(CartRepository::class)->addItem($cart, $product->id, 1);

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
                customerId: $customerId,
                source: 'storefront',
                note: null,
            ));

            $order = Order::query()->where('uuid', $placed->uuid())->firstOrFail();
            $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

            $invoiceNumber = app(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_INVOICE)->documentNumber();

            $order->forceFill(['fulfillment_status' => Order::FULFILLMENT_CANCELLED])->save();
            $creditNoteNumber = app(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_CREDIT_NOTE)->documentNumber();

            return ['invoice' => $invoiceNumber, 'credit_note' => $creditNoteNumber];
        });
    }

    /**
     * Places two orders for the given customer and issues documents across
     * them in a sequence engineered so that the resulting invoice/credit-note
     * pair sharing a printed number does NOT also share an order_id.
     *
     * Why this matters (Task 7 review, round 2): the documents table has no
     * index on `number` alone; the tenant-scoped, type-less lookup that a
     * regressed controller would run resolves via the
     * `documents_tenant_id_order_id_type_unique` index (confirmed via
     * EXPLAIN), which orders rows by (order_id, type). When an invoice and
     * its own credit note share an order_id (as in
     * issueInvoiceAndCreditNoteFor()), that index always yields the invoice
     * first regardless of insertion order — `type` is an
     * ENUM('invoice','proforma','credit_note'), and MySQL sorts enums by
     * declaration position, so 'invoice' (1) sorts before 'credit_note' (3)
     * on any order_id tie. A test built on same-order documents is therefore
     * vacuous no matter which row was inserted first: the type-less query
     * would "coincidentally" return the invoice via the index either way.
     *
     * Giving the two documents different order_ids breaks the tie on
     * order_id itself, which the index consults before type — so the
     * document with the lower order_id wins regardless of type. Sequencing
     * below deliberately gives the credit note the lower order_id:
     *
     *  1. "early" is placed first (lower order_id) but its invoice is
     *     deferred — issued only after "late"'s, so it does not claim
     *     invoice_seq=1.
     *  2. "late" is placed second (higher order_id); its invoice is issued
     *     FIRST, claiming invoice_seq=1 — this is the number under test.
     *  3. "early"'s own invoice is issued next, purely because
     *     CreditNoteIssuer::build() requires an existing invoice for the
     *     same order before a credit note can be issued — its printed
     *     number is never asserted on.
     *  4. "early" is cancelled and credited — the first credit note ever in
     *     this tenant, so credit_note_seq=1, printing the identical string
     *     "late"'s invoice got in step 2 — but attached to "early", the
     *     order with the lower order_id.
     *
     * @return array{invoice: string, credit_note: string}
     */
    private function issueInvoiceAndCreditNoteFromDifferentOrdersSharingANumber(Tenant $tenant, ?int $customerId): array
    {
        return $this->context->runAs($tenant, function () use ($customerId): array {
            $placeOrder = function () use ($customerId): Order {
                $product = app(ProductWriter::class)->create([
                    'name' => 'Klávesnice Acme',
                    'sku' => 'KB-'.bin2hex(random_bytes(4)),
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
                app(CartRepository::class)->addItem($cart, $product->id, 1);

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
                    customerId: $customerId,
                    source: 'storefront',
                    note: null,
                ));

                $order = Order::query()->where('uuid', $placed->uuid())->firstOrFail();
                $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

                return $order;
            };

            // Placed in this order so "early" gets the lower order_id.
            $early = $placeOrder();
            $late = $placeOrder();

            // "late"'s invoice is issued first, claiming invoice_seq=1 — the
            // number this test targets.
            $lateInvoiceNumber = app(DocumentIssuer::class)->issue($late->uuid, Document::TYPE_INVOICE)->documentNumber();

            // "early"'s own invoice — purely the CreditNoteIssuer::build()
            // prerequisite. Its printed number is never asserted on.
            app(DocumentIssuer::class)->issue($early->uuid, Document::TYPE_INVOICE);

            $early->forceFill(['fulfillment_status' => Order::FULFILLMENT_CANCELLED])->save();
            $earlyCreditNoteNumber = app(DocumentIssuer::class)->issue($early->uuid, Document::TYPE_CREDIT_NOTE)->documentNumber();

            return ['invoice' => $lateInvoiceNumber, 'credit_note' => $earlyCreditNoteNumber];
        });
    }

    public function test_the_owning_customer_can_download_their_invoice(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $customer = $this->makeCustomer($this->tenant);
        $number = $this->issueInvoiceFor($this->tenant, $customer->id);

        $response = $this->actingAsCustomer($customer)->get($this->url('/faktura/'.$number.'/pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Robots-Tag', 'noindex');
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_a_different_authenticated_customer_cannot_download_it(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $owner = $this->makeCustomer($this->tenant);
        $stranger = $this->makeCustomer($this->tenant);
        $number = $this->issueInvoiceFor($this->tenant, $owner->id);

        $response = $this->actingAsCustomer($stranger)->get($this->url('/faktura/'.$number.'/pdf'));

        $response->assertNotFound();
    }

    public function test_a_guest_is_redirected_to_the_customer_login(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $owner = $this->makeCustomer($this->tenant);
        $number = $this->issueInvoiceFor($this->tenant, $owner->id);

        $response = $this->call('GET', $this->url('/faktura/'.$number.'/pdf'));

        $response->assertRedirect($this->url('/prihlaseni'));
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_foreign_tenants_document_number_is_not_found(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create([
            'billing_name' => 'Shop Two s.r.o.',
            'billing_address' => ['street' => 'Vedlejší 2', 'city' => 'Brno', 'zip' => '602 00', 'country' => 'CZ'],
        ]);
        foreach (['storefront', 'customers', 'checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($other, $module);
        }

        $foreignOwner = $this->makeCustomer($other);
        $number = $this->issueInvoiceFor($other, $foreignOwner->id);

        // Requested against shop1's host, where a document with this number
        // (foreign tenant_id) is invisible to Document's BelongsToTenant scope.
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/faktura/'.$number.'/pdf'));

        $response->assertNotFound();
    }

    public function test_a_nonexistent_document_number_is_not_found(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/faktura/does-not-exist/pdf'));

        $response->assertNotFound();
    }

    // --- account order detail link -------------------------------------

    public function test_the_order_detail_page_links_to_the_invoice_once_issued(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $customer = $this->makeCustomer($this->tenant);
        $number = $this->issueInvoiceFor($this->tenant, $customer->id);

        $orderUuid = $this->context->runAs(
            $this->tenant,
            fn () => Document::query()->where('number', $number)->firstOrFail()->documentOrderUuid(),
        );

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$orderUuid));

        $response->assertOk();
        $response->assertSee(route('storefront.docs.download', ['number' => $number]), false);
    }

    public function test_the_order_detail_page_has_no_invoice_link_when_none_is_issued(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $order = $this->context->runAs($this->tenant, function () use ($customer): Order {
            return Order::query()->create([
                'number' => '2026'.random_int(100000, 999999),
                'checkout_token' => bin2hex(random_bytes(20)),
                'customer_id' => $customer->id,
                'email' => 'jana@example.cz',
                'billing' => [
                    'name' => 'Jana Nováková',
                    'street' => 'Hlavní 1',
                    'city' => 'Praha',
                    'zip' => '110 00',
                    'country' => 'CZ',
                ],
                'currency' => 'CZK',
                'items_total' => 10000,
                'total' => 10000,
                'placed_at' => now(),
            ]);
        });

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$order->uuid));

        $response->assertOk();
        $response->assertDontSee('Stáhnout fakturu');
    }

    // --- type disambiguation (Task 7 review fix) ----------------------------

    public function test_an_invoice_and_a_credit_note_actually_share_a_printed_number(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $customer = $this->makeCustomer($this->tenant);
        $numbers = $this->issueInvoiceAndCreditNoteFor($this->tenant, $customer->id);

        // The premise the test below relies on: two different series (own
        // sequence per document type), same printed number. If this ever
        // stops holding (e.g. a distinguishing prefix is added later), the
        // test below stops proving anything and must be revisited.
        $this->assertSame($numbers['invoice'], $numbers['credit_note']);
    }

    public function test_the_faktura_route_serves_the_invoice_even_when_a_credit_note_shares_its_number(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $customer = $this->makeCustomer($this->tenant);
        // Deliberately NOT issueInvoiceAndCreditNoteFor(): when an invoice
        // and its own credit note share an order_id, the type-less lookup a
        // regressed controller would run resolves via the
        // documents_tenant_id_order_id_type_unique index (confirmed via
        // EXPLAIN) — ordered by (order_id, type) — and since `type` is an
        // ENUM('invoice','proforma','credit_note'), 'invoice' always sorts
        // before 'credit_note' on an order_id tie, regardless of which row
        // was inserted first. A same-order pair is therefore vacuous no
        // matter how it's asserted (Task 7 review finding: the reviewer's
        // insertion-order framing happened to coincide with this, but
        // primary-key order turned out not to be the actual cause — see the
        // helper's docblock). Using two different orders breaks the tie on
        // order_id itself, which the index consults before type.
        $numbers = $this->issueInvoiceAndCreditNoteFromDifferentOrdersSharingANumber($this->tenant, $customer->id);
        $this->assertSame($numbers['invoice'], $numbers['credit_note']);

        $response = $this->actingAsCustomer($customer)->get($this->url('/faktura/'.$numbers['invoice'].'/pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $streamed = $response->streamedContent();
        $this->assertStringStartsWith('%PDF-', $streamed);

        // Not just "some PDF" — the *invoice's own* stored bytes, not the
        // credit note's. Both documents render distinct templates
        // (docs::pdf.invoice vs docs::pdf.credit-note) to distinct storage
        // keys (Modules\Docs\Jobs\GenerateDocumentPdf keys by type+number
        // since both can share a printed number); this pins the response to
        // the invoice's file specifically, so a regression that made the two
        // documents' PDFs collide on disk (or the controller resolve the
        // wrong row) would fail this assertion even though "some PDF came
        // back" would not have caught it.
        $this->context->runAs($this->tenant, function () use ($numbers, $streamed) {
            $invoice = Document::query()
                ->where('number', $numbers['invoice'])
                ->where('type', Document::TYPE_INVOICE)
                ->firstOrFail();
            $creditNote = Document::query()
                ->where('number', $numbers['credit_note'])
                ->where('type', Document::TYPE_CREDIT_NOTE)
                ->firstOrFail();

            $this->assertNotSame($invoice->pdf_path, $creditNote->pdf_path);

            $storage = app(FileStorage::class);
            $this->assertSame($storage->get($invoice->pdf_path), $streamed);
            $this->assertNotSame($storage->get($creditNote->pdf_path), $streamed);
        });
    }

    public function test_the_faktura_route_404s_when_only_a_credit_note_has_that_number(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $customer = $this->makeCustomer($this->tenant);
        $invoiceNumber = $this->issueInvoiceFor($this->tenant, $customer->id);

        // A number that exists only as a credit_note row — not reachable via
        // the normal issuer flow here (a credit note always implies a sibling
        // invoice for the same order), so the row is inserted directly to
        // isolate the read path under test. Document::create() is the model's
        // one allowed write (only updating()/deleting() are guarded), so this
        // does not fight the immutability rule.
        //
        // Made a fully-valid, downloadable-looking document owned by the SAME
        // customer as the invoice (Task 7 review, round 2): a populated
        // `customer` JSON carrying the real order's uuid, so
        // OrderBook::findForCustomer() succeeds, plus a real pdf_path backed
        // by an actual stored file, so the pdf_path gate also passes. Without
        // both, the request would 404 at the ownership or pdf_path gate
        // before the type filter is ever consulted, and this test would not
        // prove the filter does anything (Task 7 review finding). With both
        // gates passing, the only remaining reason left for a 404 is the
        // controller's `->where('type', Document::TYPE_INVOICE)` clause —
        // if that were removed, this row (the only one with this number)
        // would be served instead of 404ing.
        $creditNoteOnlyNumber = 'CN-ONLY-'.$invoiceNumber;

        $this->context->runAs($this->tenant, function () use ($invoiceNumber, $creditNoteOnlyNumber) {
            $invoiceDocument = Document::query()->where('number', $invoiceNumber)->firstOrFail();
            $orderId = $invoiceDocument->order_id;
            $orderUuid = Order::query()->findOrFail($orderId)->uuid;

            $path = 'documents/credit_note-'.$creditNoteOnlyNumber.'.pdf';
            app(FileStorage::class)->putPrivate($path, '%PDF-fake-credit-note-only');

            Document::query()->create([
                'order_id' => $orderId,
                'type' => Document::TYPE_CREDIT_NOTE,
                'number' => $creditNoteOnlyNumber,
                'series' => 'credit_notes:test',
                'issued_at' => now(),
                'taxable_at' => now()->toDateString(),
                'due_at' => now()->toDateString(),
                'supplier' => [],
                'customer' => ['order_uuid' => $orderUuid],
                'items' => [],
                'vat_summary' => [],
                'total' => -100,
                'currency' => 'CZK',
                'pdf_path' => $path,
            ]);
        });

        $response = $this->actingAsCustomer($customer)->get($this->url('/faktura/'.$creditNoteOnlyNumber.'/pdf'));

        $response->assertNotFound();
    }
}
