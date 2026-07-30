<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Contracts\DocumentLedger;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Exceptions\UnsupportedVatRate;
use Modules\Accounting\Support\PohodaXmlFormat;
use Modules\Docs\Models\Document;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class AccountingIsolationTest extends DocsTestCase
{
    public function test_an_export_never_contains_another_tenants_document(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        $mine = Document::query()->latest('id')->firstOrFail();

        // documents.tenant_id carries a real FK to tenants, so the planted row
        // needs an actual other tenant to reference (an arbitrary offset id
        // would just fail the FK, not exercise tenant isolation).
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        // A document belonging to nobody in this shop, inserted past the global
        // scope on purpose — the ledger must not see it.
        DB::table('documents')->insert([
            ...collect($mine->getAttributes())->except('id')->all(),
            'tenant_id' => $other->id,
            'number' => 'CIZI-1',
        ]);

        $documents = app(DocumentLedger::class)
            ->taxableBetween(now()->startOfMonth(), now()->endOfMonth());

        $this->assertFalse($documents->contains(fn ($doc) => $doc->documentNumber() === 'CIZI-1'));
        $this->assertNull(app(DocumentLedger::class)->findTaxDocument('CIZI-1', Document::TYPE_INVOICE));
    }

    public function test_an_unknown_vat_rate_stops_the_export_and_names_the_document(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        $invoice = Document::query()->latest('id')->firstOrFail();

        $items = $invoice->items;
        $items[0]['tax_rate'] = '15.00';
        DB::table('documents')->where('id', $invoice->id)->update(['items' => json_encode($items)]);

        $this->expectException(UnsupportedVatRate::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($invoice->number, '/').'/');

        (new PohodaXmlFormat)->writeOne($invoice->fresh(), []);
    }
}
