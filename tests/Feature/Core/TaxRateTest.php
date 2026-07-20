<?php

namespace Tests\Feature\Core;

use App\Core\Money\Money;
use App\Core\Tax\Exceptions\UnknownTaxRate;
use App\Core\Tax\TaxRates;
use App\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * VAT rates belong to the kernel, not to the products module (spec §6.2).
 * Orders, shipping, payments and invoicing all quote the same rate, and a
 * module that owned the list could be switched off underneath them.
 */
class TaxRateTest extends TestCase
{
    use RefreshDatabase;

    private TaxRates $rates;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->rates = app(TaxRates::class);
    }

    public function test_the_czech_rates_are_seeded(): void
    {
        $this->assertEqualsCanonicalizing(
            [210, 120, 0],
            TaxRate::query()->pluck('rate_permille')->all()
        );
    }

    public function test_rates_are_platform_wide_not_per_tenant(): void
    {
        // Rates are law, not shop configuration. A tenant_id here would invite
        // a shop to invent its own VAT.
        $this->assertFalse(Schema::hasColumn('tax_rates', 'tenant_id'));
    }

    public function test_the_standard_rate_is_the_default(): void
    {
        $this->assertSame(210, $this->rates->default()->rate_permille);
    }

    public function test_a_rate_is_found_by_code(): void
    {
        $this->assertSame(120, $this->rates->find('reduced')->rate_permille);
    }

    public function test_an_unknown_code_is_an_exception_not_a_silent_default(): void
    {
        // Falling back to the standard rate here would quietly overcharge.
        $this->expectException(UnknownTaxRate::class);

        $this->rates->find('imaginary');
    }

    public function test_net_is_derived_from_gross_to_the_haler(): void
    {
        $rate = $this->rates->default();

        $this->assertTrue(
            $rate->net(Money::fromMajor(121, 'CZK'))->equals(Money::fromMajor(100, 'CZK'))
        );
    }

    public function test_gross_is_derived_from_net_to_the_haler(): void
    {
        $rate = $this->rates->default();

        $this->assertTrue(
            $rate->gross(Money::fromMajor(100, 'CZK'))->equals(Money::fromMajor(121, 'CZK'))
        );
    }

    public function test_an_amount_that_does_not_divide_evenly_rounds_to_the_haler(): void
    {
        $rate = $this->rates->default();

        // 999.00 / 1.21 = 825.61983…
        $this->assertSame(82562, $rate->net(Money::fromMajor(999, 'CZK'))->amount);
    }

    public function test_the_vat_part_and_the_net_part_add_back_up_to_the_gross(): void
    {
        // The invoice has to balance. Deriving VAT as gross - net rather than
        // net * rate is what guarantees it, whatever the rounding did.
        $rate = $this->rates->default();
        $gross = Money::fromMajor(999, 'CZK');

        $this->assertTrue(
            $rate->net($gross)->plus($rate->vat($gross))->equals($gross)
        );
    }

    public function test_the_zero_rate_leaves_the_amount_alone(): void
    {
        $rate = $this->rates->find('zero');
        $gross = Money::fromMajor(500, 'CZK');

        $this->assertTrue($rate->net($gross)->equals($gross));
        $this->assertTrue($rate->vat($gross)->isZero());
    }

    public function test_the_list_is_cached_but_a_change_is_not_stale_forever(): void
    {
        $this->rates->all();

        TaxRate::query()->where('code', 'reduced')->update(['rate_permille' => 150]);
        $this->rates->flush();

        $this->assertSame(150, $this->rates->find('reduced')->rate_permille);
    }
}
