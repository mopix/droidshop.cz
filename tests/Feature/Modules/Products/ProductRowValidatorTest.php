<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Support\ProductRowValidator;
use Tests\TestCase;

class ProductRowValidatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validate(array $row, bool $creating = true): array
    {
        return app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(ProductRowValidator::class)->validate($row, $creating),
        );
    }

    public function test_a_complete_product_row_passes(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt',
            'sku' => 'ACME-1',
            'nazev' => 'Klávesnice',
            'cena' => '1290,00',
            'dph' => '21',
            'stav' => 'aktivni',
        ]);

        $this->assertSame([], $errors);
    }

    public function test_a_new_product_without_a_name_is_refused(): void
    {
        $errors = $this->validate(['typ' => 'produkt', 'sku' => 'ACME-1', 'cena' => '10,00', 'dph' => '21']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Název', implode(' ', $errors));
    }

    public function test_an_update_may_omit_the_name(): void
    {
        $errors = $this->validate(['typ' => 'produkt', 'sku' => 'ACME-1'], creating: false);

        $this->assertSame([], $errors);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $errors = $this->validate(['typ' => 'neco', 'sku' => 'ACME-1']);

        $this->assertNotEmpty($errors);
    }

    public function test_an_unknown_vat_rate_is_refused(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt', 'sku' => 'ACME-1', 'nazev' => 'Klávesnice',
            'cena' => '10,00', 'dph' => '17',
        ]);

        $this->assertStringContainsString('sazba DPH', implode(' ', $errors));
    }

    public function test_a_sale_price_above_the_regular_price_is_refused(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt', 'sku' => 'ACME-1', 'nazev' => 'Klávesnice',
            'cena' => '100,00', 'akcni_cena' => '150,00', 'dph' => '21',
        ]);

        $this->assertStringContainsString('Akční cena', implode(' ', $errors));
    }

    public function test_a_sale_window_that_ends_before_it_starts_is_refused(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt', 'sku' => 'ACME-1', 'nazev' => 'Klávesnice',
            'cena' => '100,00', 'akcni_cena' => '80,00', 'dph' => '21',
            'akce_od' => '2026-08-08', 'akce_do' => '2026-08-01',
        ]);

        $this->assertStringContainsString('Konec akce', implode(' ', $errors));
    }

    public function test_a_variant_row_needs_a_parent_and_axes(): void
    {
        $errors = $this->validate(['typ' => 'varianta', 'sku' => 'ACME-1-M']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('rodičovské SKU', implode(' ', $errors));
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $errors = $this->validate([
            'typ' => 'produkt', 'sku' => 'ACME-1', 'nazev' => 'Klávesnice',
            'cena' => '10,00', 'dph' => '21', 'stav' => 'zveřejněno',
        ]);

        $this->assertNotEmpty($errors);
    }
}
