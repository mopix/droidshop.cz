<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\DocumentWriter;
use Modules\Docs\Services\InvoiceIssuer;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class DocumentWriterTest extends DocsTestCase
{
    public function test_write_creates_a_numbered_document_and_is_idempotent(): void
    {
        $order = $this->placePaidOrder(); // helper from DocsTestCase, returns order uuid
        $issuer = $this->app->make(InvoiceIssuer::class);
        $writer = $this->app->make(DocumentWriter::class);

        $first = $writer->write($issuer, $order);
        $second = $writer->write($issuer, $order);

        $this->assertSame($first->id, $second->id, 'second write must return the same row');
        $this->assertSame(1, Document::query()->where('order_id', $first->order_id)->where('type', 'invoice')->count());
        $this->assertMatchesRegularExpression('/^\d{4}\d{4,}$/', $first->number); // {YYYY}{NNNN}, empty default prefix
    }

    public function test_write_throws_when_module_is_off(): void
    {
        $order = $this->placePaidOrder();
        $this->disableDocsModule(); // helper: ShopModules->has('docs') === false

        $issuer = $this->app->make(InvoiceIssuer::class);
        $writer = $this->app->make(DocumentWriter::class);

        $this->expectException(DocumentIssuanceUnavailable::class);
        $writer->write($issuer, $order);
    }
}
