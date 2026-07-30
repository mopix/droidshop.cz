<?php

namespace Tests\Unit\Modules\Accounting;

use Modules\Accounting\Support\IsdocFormat;
use PHPUnit\Framework\TestCase;

class IsdocUuidTest extends TestCase
{
    public function test_the_uuid_is_stable_for_the_same_document(): void
    {
        // Importers deduplicate on UUID: a random one would turn a re-export of
        // the same invoice into a second invoice in the accountant's software.
        $this->assertSame(
            IsdocFormat::uuidFor(7, 'invoice', '2026001'),
            IsdocFormat::uuidFor(7, 'invoice', '2026001'),
        );
    }

    public function test_it_differs_per_tenant_and_per_type(): void
    {
        $a = IsdocFormat::uuidFor(7, 'invoice', '2026001');

        $this->assertNotSame($a, IsdocFormat::uuidFor(8, 'invoice', '2026001'));
        $this->assertNotSame($a, IsdocFormat::uuidFor(7, 'credit_note', '2026001'));
    }

    public function test_it_looks_like_a_uuid(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            IsdocFormat::uuidFor(7, 'invoice', '2026001'),
        );
    }
}
