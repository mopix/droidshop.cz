<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use Modules\Accounting\Support\IsdocFormat;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * Validates the generated ISDOC against the official 6.0.1 schema. Until wave
 * 2.12 correctness rested on reading the documentation; the golden files only
 * guard drift. libxml does the validation — no new dependency.
 */
class IsdocSchemaTest extends DocsTestCase
{
    private function assertValidIsdoc(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument;
        $dom->loadXML($xml);

        $valid = $dom->schemaValidate(base_path('tests/Fixtures/isdoc/isdoc-invoice-6.0.1.xsd'));
        $errors = array_map(
            static fn (\LibXMLError $e): string => trim($e->message).' (řádek '.$e->line.')',
            libxml_get_errors(),
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($valid, "ISDOC neprošel validací proti XSD:\n".implode("\n", $errors));
    }

    public function test_an_invoice_validates_against_the_official_schema(): void
    {
        app(DocumentIssuer::class)->issue($this->placePaidOrder(), Document::TYPE_INVOICE);
        $invoice = Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();

        $this->assertValidIsdoc((new IsdocFormat)->writeOne($invoice, []));
    }

    public function test_a_credit_note_validates_against_the_official_schema(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $note = Document::query()->where('type', Document::TYPE_CREDIT_NOTE)->latest('id')->firstOrFail();

        $this->assertValidIsdoc((new IsdocFormat)->writeOne($note, []));
    }
}
