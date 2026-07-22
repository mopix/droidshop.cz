<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use App\Models\User;
use Modules\Checkout\Models\Cart;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * Wave 1.6 Task 13 — the accountant-facing CSV export over VatCsvWriter +
 * DocumentLedger: streamed, BOM'd for Excel, semicolon-separated, credit
 * notes negative, tenant isolated.
 */
class VatExportTest extends DocsTestCase
{
    public function test_export_streams_csv_with_bom_and_credit_note_negative(): void
    {
        $order = $this->issuedInvoiceOrder();
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $this->app->make(DocumentIssuer::class)
            ->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $response = $this->actingAsDocsManager()
            ->get($this->vatExportUrl('shop1.droidshop', $from, $to));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('x-robots-tag', 'noindex');

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 BOM for Excel');
        $this->assertStringContainsString(';', $body, 'semicolon separator');

        // Credit note total is negative — its row must contain a "-" figure.
        $creditNumber = Document::query()->where('type', 'credit_note')->value('number');
        $this->assertMatchesRegularExpression('/'.preg_quote($creditNumber, '/').'.*-/', $body);

        // The invoice row, by contrast, carries no negative figures.
        $lines = preg_split('/\r\n|\n/', trim($body, "\xEF\xBB\xBF"));
        $invoiceLine = current(array_filter($lines, fn ($line) => str_starts_with($line, Document::query()->where('type', 'invoice')->value('number').';')));
        $this->assertIsString($invoiceLine);
        $this->assertStringNotContainsString('-', $invoiceLine);
    }

    public function test_proforma_is_excluded_from_the_export(): void
    {
        $this->app->make(DocumentIssuer::class)
            ->issue($this->placeUnpaidBankTransferOrder(), Document::TYPE_PROFORMA);
        $proformaNumber = Document::query()->where('type', 'proforma')->value('number');

        $body = $this->actingAsDocsManager()
            ->get($this->vatExportUrl(
                'shop1.droidshop',
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ))
            ->streamedContent();

        $this->assertStringNotContainsString($proformaNumber, $body);
    }

    public function test_a_document_outside_the_requested_period_is_excluded(): void
    {
        $this->issuedInvoiceOrder();
        Document::query()->where('type', 'invoice')->update(['taxable_at' => now()->subMonth()->startOfDay()]);
        $number = Document::query()->where('type', 'invoice')->value('number');

        $body = $this->actingAsDocsManager()
            ->get($this->vatExportUrl(
                'shop1.droidshop',
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ))
            ->streamedContent();

        $this->assertStringNotContainsString($number, $body);
    }

    public function test_tenant_isolation_export_omits_other_tenants(): void
    {
        // Issue a document under tenant A (the default test tenant), then run
        // the export as tenant B's own owner, against tenant B's own host.
        $this->issuedInvoiceOrder();
        $numberA = Document::query()->where('type', 'invoice')->value('number');

        $body = $this->actingAsOtherTenantDocsManager()
            ->get($this->vatExportUrl(
                'shop2.droidshop',
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ))
            ->streamedContent();

        $this->assertStringNotContainsString($numberA, $body);
    }

    public function test_a_staff_member_without_the_manage_permission_is_forbidden(): void
    {
        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, ['role' => 'staff', 'permissions' => [], 'joined_at' => now()]);

        $this->actingAs($staff)
            ->get($this->vatExportUrl(
                'shop1.droidshop',
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ))
            ->assertForbidden();
    }

    public function test_an_invalid_range_fails_validation(): void
    {
        $this->actingAsDocsManager()
            ->get($this->vatExportUrl('shop1.droidshop', now()->toDateString(), now()->subDay()->toDateString()))
            ->assertSessionHasErrors('to');
    }

    /**
     * CWE-1236 — CSV formula injection. A customer's billing name is
     * free-typed at checkout (PlaceOrderRequest only length/type-validates
     * it) and flows unmodified into the export via the invoice's `customer`
     * snapshot (InvoiceSnapshot -> Order::orderBilling()). A value like
     * `=HYPERLINK(...)` must never appear at the start of a CSV cell, or
     * Excel/LibreOffice evaluates it as a formula against the tenant's
     * accountant. VatCsvWriter::neutralize() must prefix such a cell with a
     * leading single quote so it opens as inert text instead.
     */
    public function test_a_malicious_billing_name_is_neutralized_in_the_export(): void
    {
        $payload = '=HYPERLINK("http://evil.example","click")';
        $order = $this->issuedInvoiceOrderWithBillingName($payload);
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $this->app->make(DocumentIssuer::class)
            ->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $body = $this->actingAsDocsManager()
            ->get($this->vatExportUrl(
                'shop1.droidshop',
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ))
            ->streamedContent();

        // fputcsv wraps this field in double quotes (it contains '"' and the
        // ';' delimiter's sibling special chars) and doubles internal '"'.
        // The neutralized cell therefore reads: "'<payload with "" doubled>".
        $escapedPayload = str_replace('"', '""', $payload);
        $this->assertStringContainsString('"\''.$escapedPayload.'"', $body);
        // And the raw, unescaped payload must never start a cell — i.e. it
        // never appears immediately after a ';' separator, a '"' quote
        // opener, or line start, only after the neutralizing quote.
        $this->assertDoesNotMatchRegularExpression(
            '/(?<=[;"\r\n]|^)'.preg_quote($escapedPayload, '/').'/',
            $body,
        );
    }

    /**
     * Same export, but proves the credit note's legitimate negative money
     * columns were exempted from neutralize() — a "-1234,50" total must
     * remain a bare number (no leading quote), so the accountant's SUM()
     * still parses it, while the malicious text column right next to it on
     * the very same row was still escaped.
     */
    public function test_negative_credit_note_money_is_not_quoted_while_dic_is(): void
    {
        $order = $this->issuedInvoiceOrderWithBillingDic('=1+1');
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $this->app->make(DocumentIssuer::class)
            ->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $body = $this->actingAsDocsManager()
            ->get($this->vatExportUrl(
                'shop1.droidshop',
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ))
            ->streamedContent();

        // Filter on the "typ" column (';dobropis;'), not on "cislo": invoice
        // and credit-note numbers are independent per-type sequences and can
        // legitimately collide (both start counting at 1).
        $lines = preg_split('/\r\n|\n/', trim($body, "\xEF\xBB\xBF"));
        $creditLine = current(array_filter($lines, fn ($line) => str_contains($line, ';dobropis;')));
        $this->assertIsString($creditLine);

        // The DIC column was neutralized...
        $this->assertStringContainsString("'=1+1", $creditLine);
        // ...but the negative money columns on the same row were left bare:
        // a plain "-1234,00"-shaped figure, never "'-1234,00".
        $this->assertMatchesRegularExpression('/;-\d[\d ]*,\d{2};/', $creditLine);
        $this->assertStringNotContainsString("'-", $creditLine);
    }

    /**
     * Places a real order through checkout (same shape as
     * DocsTestCase::placePaidOrder()) but with a caller-supplied billing
     * name, force-settles it paid, issues its invoice, and returns the Order.
     */
    private function issuedInvoiceOrderWithBillingName(string $billingName): Order
    {
        return $this->issuedInvoiceOrderWithBilling(['name' => $billingName]);
    }

    private function issuedInvoiceOrderWithBillingDic(string $dic): Order
    {
        return $this->issuedInvoiceOrderWithBilling(['dic' => $dic]);
    }

    /**
     * @param  array<string, string>  $billingOverrides
     */
    private function issuedInvoiceOrderWithBilling(array $billingOverrides): Order
    {
        $product = app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'sku' => 'KB-INJ',
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
            billing: array_merge([
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
            ], $billingOverrides),
            shipping: null,
            checkoutToken: 'tok-'.bin2hex(random_bytes(8)),
            customerId: null,
            source: 'storefront',
            note: null,
        ));

        $order = Order::query()->where('uuid', $placed->uuid())->firstOrFail();
        $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

        $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_INVOICE);

        return $order->fresh();
    }
}
