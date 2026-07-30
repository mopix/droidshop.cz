<?php

namespace Tests\Unit\Modules\Accounting;

use Modules\Accounting\Exceptions\UnsupportedVatRate;
use Modules\Accounting\Support\VatRateMap;
use PHPUnit\Framework\TestCase;

class VatRateMapTest extends TestCase
{
    public function test_it_maps_the_three_czech_rates(): void
    {
        $this->assertSame('high', VatRateMap::pohoda(21, '2026001'));
        $this->assertSame('low', VatRateMap::pohoda(12, '2026001'));
        $this->assertSame('none', VatRateMap::pohoda(0, '2026001'));
    }

    public function test_an_unknown_rate_is_refused_and_names_the_document(): void
    {
        // Pohoda has three non-zero levels. A silent fallback would import the
        // wrong tax into someone's books, so the export stops instead — the
        // same conclusion as the mandatory tax_rate_id in wave 2.7.
        $this->expectException(UnsupportedVatRate::class);
        $this->expectExceptionMessageMatches('/2026001/');

        VatRateMap::pohoda(15, '2026001');
    }
}
