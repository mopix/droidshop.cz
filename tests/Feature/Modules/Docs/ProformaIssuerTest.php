<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Storage\FileStorage;
use Illuminate\Support\Facades\Storage;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * Wave 1.6 Stage 4 — the proforma issuer's rule (spec §16.6): no DUZP, its
 * own number series, and no gate — it coexists freely alongside an invoice on
 * the same order (unlike a credit note, it corrects nothing and requires
 * none).
 */
class ProformaIssuerTest extends DocsTestCase
{
    public function test_proforma_has_no_duzp_and_its_own_series(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $orderUuid = $this->placeUnpaidBankTransferOrder();

        $doc = app(DocumentIssuer::class)->issue($orderUuid, Document::TYPE_PROFORMA);

        $this->assertSame(Document::TYPE_PROFORMA, $doc->documentType());

        $row = Document::query()->where('type', 'proforma')->first();
        $this->assertNull($row->taxable_at, 'a proforma carries no DUZP');
        $this->assertStringStartsWith('proformas:', $row->series);
    }

    public function test_proforma_and_invoice_coexist_on_one_order(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $orderUuid = $this->placeUnpaidBankTransferOrder();
        $issuer = app(DocumentIssuer::class);

        $issuer->issue($orderUuid, Document::TYPE_PROFORMA);

        Order::query()->where('uuid', $orderUuid)->firstOrFail()
            ->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

        $issuer->issue($orderUuid, Document::TYPE_INVOICE);

        $this->assertSame(1, Document::query()->where('type', 'proforma')->count());
        $this->assertSame(1, Document::query()->where('type', 'invoice')->count());
    }

    public function test_issuing_a_proforma_twice_for_the_same_order_stays_idempotent(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $orderUuid = $this->placeUnpaidBankTransferOrder();
        $issuer = app(DocumentIssuer::class);

        $first = $issuer->issue($orderUuid, Document::TYPE_PROFORMA);
        $second = $issuer->issue($orderUuid, Document::TYPE_PROFORMA);

        $this->assertSame($first->documentNumber(), $second->documentNumber());
        $this->assertSame(1, Document::query()->where('type', 'proforma')->count());
    }
}
