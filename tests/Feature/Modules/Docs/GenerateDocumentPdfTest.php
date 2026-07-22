<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Storage\FileStorage;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Checkout\Models\Cart;
use Modules\Docs\Jobs\GenerateDocumentPdf;
use Modules\Docs\Models\Document;
use Modules\Docs\Support\DocumentQr;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Wave 1.5 Task 5 — rendering the invoice PDF (dompdf) and writing its
 * pdf_path, the one post-issue mutation Document::booted() still allows.
 * Renamed from GenerateInvoicePdfTest in wave 1.6 (Stage 2): the job under
 * test is now type-agnostic (GenerateDocumentPdf), though every scenario here
 * still exercises it against an invoice.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so DocumentWriter::write()'s
 * GenerateDocumentPdf::dispatch() runs inline in these tests exactly like it
 * would on a real sync-driver deploy — no manual dispatch or Bus faking
 * needed to exercise the job.
 */
class GenerateDocumentPdfTest extends TestCase
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

        foreach (['checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    // --- helpers ------------------------------------------------------

    /**
     * Places an order paid by COD (no bank account, so never QR-eligible) and
     * force-settles it paid — mirrors InvoiceIssuerTest::placePaidOrder().
     */
    private function placePaidCodOrder(): Order
    {
        return $this->context->runAs($this->tenant, function (): Order {
            $order = $this->placeOrder(PaymentMethod::PROVIDER_COD, []);
            $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

            return $order;
        });
    }

    /**
     * Places an order paid by bank transfer, left unpaid — the only state
     * that makes the invoice QR-eligible.
     */
    private function placeUnpaidBankTransferOrder(): Order
    {
        return $this->context->runAs(
            $this->tenant,
            fn (): Order => $this->placeOrder(PaymentMethod::PROVIDER_BANK_TRANSFER, ['account' => 'CZ6508000000192000145399'])
        );
    }

    private function placeOrder(string $paymentProvider, array $paymentSettings): Order
    {
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
            'provider' => $paymentProvider,
            'name' => $paymentProvider === PaymentMethod::PROVIDER_COD ? 'Dobírka' : 'Bankovní převod',
            'fee' => 0,
            'currency' => 'CZK',
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'is_active' => true,
            'settings' => $paymentSettings,
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

        return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
    }

    private function issue(string $uuid): Document
    {
        /** @var Document $document */
        $document = $this->context->runAs($this->tenant, fn () => app(DocumentIssuer::class)->issue($uuid));

        return $document;
    }

    private function issueType(string $uuid, string $type): Document
    {
        /** @var Document $document */
        $document = $this->context->runAs($this->tenant, fn () => app(DocumentIssuer::class)->issue($uuid, $type));

        return $document;
    }

    // --- scenarios ------------------------------------------------------

    public function test_pdf_job_writes_file_and_sets_path(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placePaidCodOrder();

        // InvoiceIssuer dispatches GenerateDocumentPdf, which runs inline
        // (sync queue) before issue() returns.
        $issued = $this->issue($order->uuid);

        $path = $this->context->runAs($this->tenant, fn () => $issued->fresh()->pdf_path);

        $this->assertNotNull($path);
        $this->assertTrue($this->context->runAs($this->tenant, fn () => app(FileStorage::class)->exists($path)));

        $contents = $this->context->runAs($this->tenant, fn () => app(FileStorage::class)->get($path));
        $this->assertStringStartsWith('%PDF-', $contents);
    }

    public function test_calling_the_job_directly_is_tenant_aware_from_the_carried_tenant_id(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        // Bus::fake intercepts DocumentWriter's own GenerateDocumentPdf::dispatch(),
        // so the document is created with no pdf_path yet — the job below is
        // the only thing that renders the PDF, proving handle() itself,
        // called with just the two scalar constructor args (no ambient tenant
        // context left from a wrapping runAs), restores enough tenant context
        // to read the tenant-scoped document and write under the right
        // tenant's storage prefix.
        Bus::fake([GenerateDocumentPdf::class]);
        $order = $this->placePaidCodOrder();
        $document = $this->issue($order->uuid);
        Bus::assertDispatched(GenerateDocumentPdf::class);

        $this->context->forget();

        app()->call([new GenerateDocumentPdf($this->tenant->id, $document->id), 'handle']);

        $path = $this->context->runAs($this->tenant, fn () => $document->fresh()->pdf_path);
        $this->assertNotNull($path);
        $this->assertTrue($this->context->runAs($this->tenant, fn () => app(FileStorage::class)->exists($path)));
    }

    public function test_an_unpaid_bank_transfer_invoice_renders_with_a_qr_and_still_produces_a_valid_pdf(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placeUnpaidBankTransferOrder();

        $issued = $this->issue($order->uuid);

        $path = $this->context->runAs($this->tenant, fn () => $issued->fresh()->pdf_path);
        $this->assertNotNull($path);

        $contents = $this->context->runAs($this->tenant, fn () => app(FileStorage::class)->get($path));
        $this->assertStringStartsWith('%PDF-', $contents);
    }

    public function test_invoice_qr_builds_a_valid_spayd_string(): void
    {
        $spayd = DocumentQr::spayd(
            'CZ6508000000192000145399',
            new Money(45000, 'CZK'),
            'Faktura 2026001',
        );

        $this->assertSame('SPD*1.0*ACC:CZ6508000000192000145399*AM:450.00*CC:CZK*X-VS:2026001', $spayd);
    }

    public function test_invoice_qr_data_uri_renders_a_png(): void
    {
        $spayd = DocumentQr::spayd('CZ6508000000192000145399', new Money(45000, 'CZK'), '2026001');

        $uri = DocumentQr::dataUri($spayd);

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
    }

    public function test_a_paid_order_never_gets_a_qr_even_though_a_bank_account_exists(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->context->runAs($this->tenant, function (): Order {
            $order = $this->placeOrder(PaymentMethod::PROVIDER_BANK_TRANSFER, ['account' => 'CZ6508000000192000145399']);
            $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

            return $order;
        });

        $issued = $this->issue($order->uuid);

        // The job must not blow up rendering a paid bank-transfer invoice
        // (qr = null path) — the PDF still comes out.
        $path = $this->context->runAs($this->tenant, fn () => $issued->fresh()->pdf_path);
        $this->assertNotNull($path);
    }

    /**
     * Task 5 review fix: a QR resolution failure (here, a payment method row
     * whose `settings` cannot be decrypted — e.g. a rotated APP_KEY) must
     * degrade to no QR, never abort the PDF. The failure has to happen inside
     * bankAccount()/spaydAccount(), before DocumentQr::dataUri()'s own
     * try/catch is even reached, so this specifically exercises the guard
     * wrapped around the whole qrDataUri() call in GenerateDocumentPdf::handle().
     */
    public function test_a_qr_resolution_failure_still_produces_a_pdf(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placeUnpaidBankTransferOrder();

        // Corrupt the payment method's encrypted settings column directly in
        // the DB (bypassing Eloquent's encrypted:array cast on write), so
        // reading ->settings on the model throws a DecryptException the
        // moment DocumentQr's caller touches spaydAccount().
        $this->context->runAs($this->tenant, function () use ($order): void {
            $paymentId = $order->payment_snapshot['id'] ?? null;
            DB::table('payment_methods')->where('id', $paymentId)->update(['settings' => 'not-a-valid-encrypted-payload']);
        });

        $issued = $this->issue($order->uuid);

        $path = $this->context->runAs($this->tenant, fn () => $issued->fresh()->pdf_path);
        $this->assertNotNull($path);

        $contents = $this->context->runAs($this->tenant, fn () => app(FileStorage::class)->get($path));
        $this->assertStringStartsWith('%PDF-', $contents);
    }

    /**
     * A credit note renders through its own template (docs::pdf.credit-note)
     * and, since it is only issuable once the order is cancelled/refunded and
     * already has an invoice, must be written under a path distinct from that
     * invoice's — Task 7 Fix D keys the on-disk filename by type as well as
     * number, because the two series can print the same number for a given
     * tenant (see GenerateDocumentPdf's own docblock on `$path`).
     */
    public function test_a_credit_note_renders_its_own_pdf(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placePaidCodOrder();
        $invoice = $this->issue($order->uuid);

        $this->context->runAs($this->tenant, function () use ($order): void {
            $order->forceFill(['fulfillment_status' => Order::FULFILLMENT_CANCELLED])->save();
        });

        $creditNote = $this->issueType($order->uuid, Document::TYPE_CREDIT_NOTE);

        $invoicePath = $this->context->runAs($this->tenant, fn () => $invoice->fresh()->pdf_path);
        $creditNotePath = $this->context->runAs($this->tenant, fn () => $creditNote->fresh()->pdf_path);

        $this->assertNotNull($invoicePath);
        $this->assertNotNull($creditNotePath);
        $this->assertTrue($this->context->runAs($this->tenant, fn () => app(FileStorage::class)->exists($creditNotePath)));

        $creditNoteContents = $this->context->runAs($this->tenant, fn () => app(FileStorage::class)->get($creditNotePath));
        $this->assertStringStartsWith('%PDF-', $creditNoteContents);

        // Invoice and credit note are independent series (config/documents.php:
        // invoice_series 'invoices' vs credit_note_series 'credit_notes'), each
        // with its own per-tenant sequence starting at 1 — with no prefix
        // configured on either, the first document of each type prints the
        // *same* number. Confirmed here rather than assumed, because it is
        // exactly the collision that would silently overwrite one PDF with the
        // other on disk if GenerateDocumentPdf's path were keyed by number alone.
        $this->assertSame($invoice->fresh()->number, $creditNote->fresh()->number);

        // The paths must differ regardless of the number collision above.
        $this->assertNotSame($invoicePath, $creditNotePath);
    }
}
