<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;
use App\Core\Documents\NullDocumentIssuer;
use Modules\Docs\Services\DocumentIssuerRegistry;
use Tests\TestCase;

class NullDocumentIssuerTest extends TestCase
{
    public function test_deploy_binds_the_document_issuer_registry(): void
    {
        // The docs module ships on disk, so its ModuleProvider overrides the
        // kernel's null default (last bind wins) with DocumentIssuerRegistry,
        // which dispatches by type to a TypedDocumentIssuer (wave 1.6). The
        // "module inactive for this tenant" case is handled at call time
        // inside DocumentWriter, which throws the same DocumentIssuanceUnavailable
        // the null binding would — see DocumentWriterTest for that path.
        $this->assertInstanceOf(DocumentIssuerRegistry::class, app(DocumentIssuer::class));
    }

    public function test_null_issuer_refuses_to_issue(): void
    {
        // The kernel default an issuer-less deploy would resolve to.
        $this->expectException(DocumentIssuanceUnavailable::class);

        (new NullDocumentIssuer)->issue('any-uuid');
    }
}
