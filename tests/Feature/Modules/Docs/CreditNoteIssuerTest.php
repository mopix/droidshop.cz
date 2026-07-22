<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class CreditNoteIssuerTest extends DocsTestCase
{
    public function test_credit_note_is_idempotent_and_uses_its_own_series(): void
    {
        $order = $this->issuedInvoiceOrder();
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $issuer = $this->app->make(DocumentIssuer::class);

        $first = $issuer->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
        $second = $issuer->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $this->assertSame($first->documentNumber(), $second->documentNumber());
        $this->assertSame(1, Document::query()->where('type', 'credit_note')->count());

        $row = Document::query()->where('type', 'credit_note')->first();
        $this->assertStringContainsString(':', $row->series); // credit_notes:{year}
        $this->assertStringStartsWith('credit_notes:', $row->series);
    }
}
