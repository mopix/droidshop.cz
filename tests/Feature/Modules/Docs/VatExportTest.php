<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Models\User;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
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
}
