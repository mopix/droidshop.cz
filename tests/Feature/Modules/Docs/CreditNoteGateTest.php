<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use Modules\Docs\Exceptions\CreditNoteNotAllowed;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class CreditNoteGateTest extends DocsTestCase
{
    public function test_cancelled_order_with_invoice_may_be_credited(): void
    {
        $order = $this->issuedInvoiceOrder(); // helper: order + issued invoice, returns Order
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);

        $doc = $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $this->assertSame(Document::TYPE_CREDIT_NOTE, $doc->documentType());
        $this->assertLessThan(0, $doc->documentTotal()->amount);
    }

    public function test_refunded_order_with_invoice_may_be_credited(): void
    {
        $order = $this->issuedInvoiceOrder();
        $order->update(['payment_status' => Order::PAYMENT_REFUNDED]);

        $doc = $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
        $this->assertSame(Document::TYPE_CREDIT_NOTE, $doc->documentType());
    }

    public function test_order_without_an_invoice_cannot_be_credited(): void
    {
        $order = $this->cancelledOrderWithoutInvoice(); // helper

        $this->expectException(CreditNoteNotAllowed::class);
        $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
    }

    public function test_active_order_with_invoice_cannot_be_credited(): void
    {
        $order = $this->issuedInvoiceOrder(); // still FULFILLMENT_NEW / PAYMENT_PAID, not cancelled/refunded

        $this->expectException(CreditNoteNotAllowed::class);
        $this->app->make(DocumentIssuer::class)->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
    }
}
