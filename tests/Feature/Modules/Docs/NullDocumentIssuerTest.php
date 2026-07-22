<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;
use App\Core\Documents\NullDocumentIssuer;
use Modules\Docs\Services\InvoiceIssuer;
use Tests\TestCase;

class NullDocumentIssuerTest extends TestCase
{
    public function test_deploy_binds_the_invoice_issuer(): void
    {
        // The docs module ships on disk, so its ModuleProvider overrides the
        // kernel's null default (last bind wins). The "module inactive for this
        // tenant" case is handled at call time inside InvoiceIssuer, which
        // throws the same DocumentIssuanceUnavailable the null binding would —
        // see InvoiceIssuerTest for that path.
        $this->assertInstanceOf(InvoiceIssuer::class, app(DocumentIssuer::class));
    }

    public function test_null_issuer_refuses_to_issue(): void
    {
        // The kernel default an issuer-less deploy would resolve to.
        $this->expectException(DocumentIssuanceUnavailable::class);

        (new NullDocumentIssuer)->issue('any-uuid');
    }
}
