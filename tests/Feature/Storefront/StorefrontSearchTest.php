<?php

namespace Tests\Feature\Storefront;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Support\SearchText;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Storefront search (spec §4.1, §16.1).
 *
 * Czech is the whole reason the normalised column exists: without folding,
 * "cerna bunda" finds nothing and the shop looks broken.
 */
class StorefrontSearchTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenantA = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);
        $this->tenantB = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);

        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            foreach (['categories', 'products', 'storefront'] as $module) {
                $this->activateModule($tenant, $module);
            }
        }
    }

    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Černá bunda zimní',
            'price' => 1_990_00,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
        ]));
    }

    public function test_normalisation_folds_case_and_diacritics(): void
    {
        $this->assertSame('cerna bunda zimni', SearchText::normalise('Černá bunda zimní'));
        $this->assertSame('cerna bunda acme-1', SearchText::normalise('Černá bunda', 'ACME-1'));
        $this->assertSame('popis', SearchText::normalise('<p>Popis</p>'));
    }

    public function test_search_finds_a_product_written_without_diacritics(): void
    {
        $this->makeProduct($this->tenantA);

        $this->get('http://shop1.droidshop/hledani?q=cerna')
            ->assertOk()
            ->assertSee('Černá bunda zimní');
    }

    public function test_search_finds_a_product_by_sku(): void
    {
        $this->makeProduct($this->tenantA, ['sku' => 'ACME-99']);

        $this->get('http://shop1.droidshop/hledani?q=acme-99')
            ->assertOk()
            ->assertSee('Černá bunda zimní');
    }

    public function test_search_does_not_cross_tenants(): void
    {
        $this->makeProduct($this->tenantA, ['name' => 'Tajna bunda']);

        $this->get('http://shop2.droidshop/hledani?q=bunda')
            ->assertOk()
            ->assertDontSee('Tajna bunda');
    }

    public function test_a_one_character_query_asks_for_more_instead_of_listing_everything(): void
    {
        $this->makeProduct($this->tenantA);

        $this->get('http://shop1.droidshop/hledani?q=c')
            ->assertOk()
            ->assertSee('alespoň dva znaky')
            ->assertDontSee('Černá bunda zimní');
    }

    public function test_search_results_are_never_indexed(): void
    {
        $this->makeProduct($this->tenantA);

        $this->get('http://shop1.droidshop/hledani?q=bunda')
            ->assertSee('content="noindex, follow"', false);
    }

    public function test_draft_products_do_not_show_up_in_search(): void
    {
        $this->makeProduct($this->tenantA, ['name' => 'Rozpracovana bunda', 'status' => Product::STATUS_DRAFT]);

        $this->get('http://shop1.droidshop/hledani?q=bunda')
            ->assertOk()
            ->assertDontSee('Rozpracovana bunda');
    }

    public function test_reindex_command_rebuilds_the_column(): void
    {
        $this->makeProduct($this->tenantA);

        // Simulates rows written before the column existed.
        DB::table('products')->update(['search_text' => null]);

        $this->artisan('products:reindex-search')->assertSuccessful();

        $this->get('http://shop1.droidshop/hledani?q=cerna')
            ->assertOk()
            ->assertSee('Černá bunda zimní');
    }
}
