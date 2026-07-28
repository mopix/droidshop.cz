<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ProductCsvExportTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'products');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function seedProduct(): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'sku' => 'ACME-1',
            'price' => 129000,
            'purchase_price' => 90000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));
    }

    private function download(): string
    {
        $response = $this->actingAs($this->owner)->get('http://shop1.droidshop/admin/m/products/export');
        $response->assertOk();

        return $response->streamedContent();
    }

    public function test_the_export_carries_the_catalogue(): void
    {
        $this->seedProduct();

        $csv = $this->download();

        $this->assertStringContainsString('typ;sku', $csv);
        $this->assertStringContainsString('ACME-1', $csv);
        $this->assertStringContainsString('1290,00', $csv);
    }

    public function test_the_purchase_price_needs_the_costs_permission(): void
    {
        $this->seedProduct();

        $csv = $this->download();

        // The owner has products.costs, so the column is there.
        $this->assertStringContainsString('nakupni_cena', $csv);
        $this->assertStringContainsString('900,00', $csv);
    }

    /**
     * The purchase price is the shop's margin. Someone allowed to edit the
     * catalogue but not to see costs must not get them via the export — the
     * same rule the product detail screen enforces.
     */
    public function test_a_user_without_the_costs_permission_gets_no_purchase_price(): void
    {
        $this->seedProduct();

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => ['products.view', 'products.edit'],
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($staff)->get('http://shop1.droidshop/admin/m/products/export');
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringNotContainsString('nakupni_cena', $csv);
        $this->assertStringNotContainsString('900,00', $csv);
        // Still a usable export, just without the margin column.
        $this->assertStringContainsString('ACME-1', $csv);
    }

    public function test_a_formula_in_a_name_is_neutralised(): void
    {
        $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => '=HYPERLINK("http://evil","klik")',
            'sku' => 'EVIL-1',
            'price' => 10000,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $csv = $this->download();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_a_variant_gets_its_own_row(): void
    {
        $product = $this->seedProduct();

        $this->context->runAs($this->tenant, fn () => app(VariantWriter::class)
            ->upsertVariant($product, ['Velikost' => 'M'], ['sku' => 'ACME-1-M', 'price' => 139000]));

        $csv = $this->download();

        $this->assertStringContainsString('varianta;ACME-1-M', $csv);
        $this->assertStringContainsString('Velikost:M', $csv);
    }
}
