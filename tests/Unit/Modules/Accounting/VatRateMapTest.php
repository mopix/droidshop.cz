<?php

namespace Tests\Unit\Modules\Accounting;

use Modules\Accounting\Exceptions\UnsupportedVatRate;
use Modules\Accounting\Support\VatRateMap;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The map used to round the percent, so a rate nobody charged slipped
     * through as one that was (final review, wave 2.11): 20.6 landed on `high`
     * and 11.5 on `low`. Only the exact rates pass now.
     */
    #[DataProvider('nearMisses')]
    public function test_a_rate_that_is_only_nearly_ours_is_refused(int|float $percent): void
    {
        $this->expectException(UnsupportedVatRate::class);

        VatRateMap::pohoda($percent, '2026001');
    }

    /**
     * @return array<string, array{int|float}>
     */
    public static function nearMisses(): array
    {
        return [
            'just under the high rate' => [20.6],
            'just over the high rate' => [21.4],
            'between the two rates' => [11.5],
            'just over zero' => [0.5],
        ];
    }

    public function test_the_percent_is_carried_verbatim_for_formats_that_print_it(): void
    {
        // ISDOC writes the number, not a level, but through the same map — a
        // whole-number string, so `21.00` off a snapshot and `21` off a
        // hand-built array produce identical XML.
        $this->assertSame('21', VatRateMap::percent(21.0, '2026001'));
        $this->assertSame('12', VatRateMap::percent((float) '12.00', '2026001'));
        $this->assertSame('0', VatRateMap::percent(0, '2026001'));
    }

    public function test_the_percent_refuses_exactly_what_pohoda_refuses(): void
    {
        $this->expectException(UnsupportedVatRate::class);
        $this->expectExceptionMessageMatches('/2026001/');

        VatRateMap::percent(15, '2026001');
    }
}
