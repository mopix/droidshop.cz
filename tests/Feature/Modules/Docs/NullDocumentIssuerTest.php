<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Documents\Exceptions\DocumentIssuanceUnavailable;
use App\Core\Documents\NullDocumentIssuer;
use Tests\TestCase;

class NullDocumentIssuerTest extends TestCase
{
    public function test_kernel_binds_null_issuer_by_default(): void
    {
        // Modul docs se v testu nezaktivoval, takže platí jádrový null binding.
        $this->assertInstanceOf(NullDocumentIssuer::class, app(DocumentIssuer::class));
    }

    public function test_null_issuer_refuses_to_issue(): void
    {
        $this->expectException(DocumentIssuanceUnavailable::class);

        (new NullDocumentIssuer)->issue('any-uuid');
    }
}
