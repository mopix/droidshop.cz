<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * Wave 1.6 Task 12 — DocumentLedger, the period read behind the VAT CSV
 * export. Invoice + credit_note only, scoped by DUZP (taxable_at), tenant
 * isolated via Document's own BelongsToTenant scope.
 */
class DocumentLedgerTest extends DocsTestCase
{
    public function test_it_returns_invoices_and_credit_notes_in_range_excludes_proforma(): void
    {
        $order = $this->issuedInvoiceOrder(); // invoice taxable today
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $this->app->make(DocumentIssuer::class)
            ->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
        $this->app->make(DocumentIssuer::class)
            ->issue($this->placeUnpaidBankTransferOrder(), Document::TYPE_PROFORMA);

        $ledger = $this->app->make(DocumentLedger::class)
            ->taxableBetween(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());

        $types = $ledger->map(fn ($d) => $d->documentType())->all();
        $this->assertContains('invoice', $types);
        $this->assertContains('credit_note', $types);
        $this->assertNotContains('proforma', $types);
    }

    public function test_range_is_by_taxable_at(): void
    {
        $this->issuedInvoiceOrder();
        // Force the invoice's DUZP into last month.
        Document::query()->where('type', 'invoice')->update(['taxable_at' => Carbon::now()->subMonth()->startOfDay()]);

        $thisMonth = $this->app->make(DocumentLedger::class)
            ->taxableBetween(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());

        $this->assertCount(0, $thisMonth);
    }

    public function test_the_range_boundaries_are_inclusive(): void
    {
        $this->issuedInvoiceOrder();
        Document::query()->where('type', 'invoice')->update(['taxable_at' => Carbon::now()->startOfMonth()]);

        $ledger = $this->app->make(DocumentLedger::class)
            ->taxableBetween(Carbon::now()->startOfMonth(), Carbon::now()->startOfMonth());

        $this->assertCount(1, $ledger);
    }

    public function test_results_are_ordered_by_taxable_at_then_number(): void
    {
        $first = $this->issuedInvoiceOrder();
        $second = $this->issuedInvoiceOrder();

        Document::query()->where('order_id', $first->id)->update(['taxable_at' => Carbon::now()->startOfMonth()->addDay()]);
        Document::query()->where('order_id', $second->id)->update(['taxable_at' => Carbon::now()->startOfMonth()]);

        $ledger = $this->app->make(DocumentLedger::class)
            ->taxableBetween(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());

        $this->assertSame(
            Document::query()->where('order_id', $second->id)->value('number'),
            $ledger->first()->documentNumber(),
        );
    }

    public function test_another_tenants_documents_never_appear(): void
    {
        $ours = $this->issuedInvoiceOrder();
        $oursNumber = Document::query()->where('order_id', $ours->id)->value('number');

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create([
            'billing_name' => 'Shop Two s.r.o.',
            'billing_address' => ['street' => 'Vedlejší 2', 'city' => 'Brno', 'zip' => '602 00', 'country' => 'CZ'],
        ]);
        foreach (['checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($other, $module);
        }

        // Another tenant's own invoice, taxable in the same period — must
        // stay invisible to this tenant's export no matter the date overlap.
        $this->context->runAs($other, function (): void {
            $this->issuedInvoiceOrder();
        });

        $ledger = $this->app->make(DocumentLedger::class)
            ->taxableBetween(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());

        $this->assertCount(1, $ledger);
        $this->assertSame($oursNumber, $ledger->first()->documentNumber());
    }

    public function test_it_finds_an_invoice_by_number_and_type(): void
    {
        $uuid = $this->placePaidOrder();
        $invoice = app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);

        $found = app(DocumentLedger::class)
            ->findTaxDocument($invoice->documentNumber(), Document::TYPE_INVOICE);

        $this->assertNotNull($found);
        $this->assertSame($invoice->documentNumber(), $found->documentNumber());
    }

    public function test_it_refuses_a_proforma_even_when_the_number_matches(): void
    {
        // A proforma is not a tax document, and since wave 1.6 the unique key is
        // (tenant_id, type, number) — so it may legitimately carry the same
        // number as an invoice. Answering with it would let a caller present a
        // non-tax document as one.
        $uuid = $this->placePaidOrder();
        $proforma = app(DocumentIssuer::class)->issue($uuid, Document::TYPE_PROFORMA);

        $this->assertNull(
            app(DocumentLedger::class)
                ->findTaxDocument($proforma->documentNumber(), Document::TYPE_PROFORMA)
        );
    }

    public function test_it_finds_a_credit_note(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $note = app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $found = app(DocumentLedger::class)
            ->findTaxDocument($note->documentNumber(), Document::TYPE_CREDIT_NOTE);

        $this->assertNotNull($found);
        $this->assertSame(Document::TYPE_CREDIT_NOTE, $found->documentType());
    }

    public function test_an_unknown_number_is_null(): void
    {
        $this->assertNull(
            app(DocumentLedger::class)->findTaxDocument('NEEXISTUJE', Document::TYPE_INVOICE)
        );
    }
}
