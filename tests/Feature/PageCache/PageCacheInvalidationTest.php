<?php

namespace Tests\Feature\PageCache;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class PageCacheInvalidationTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Generations $generations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->generations = app(Generations::class);
    }

    private function shop(): Tenant
    {
        $tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($tenant, 'products');
        $this->activateModule($tenant, 'storefront');
        $this->context->set($tenant);

        return $tenant;
    }

    /**
     * No Product factory exists in this codebase (2026-07-28 decision: writes
     * go exclusively through ProductWriter/VariantWriter so sanitisation,
     * slugging and price history stay intact) — the same helper shape used by
     * StorefrontCatalogTest::makeProduct().
     */
    private function makeProduct(): Product
    {
        return app(ProductWriter::class)->create([
            'name' => 'Testovaci produkt',
            'price' => 1_990_00,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]);
    }

    public function test_saving_a_product_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();

        $this->makeProduct();

        $this->assertSame('2', $this->generations->stamp($tenant->fresh(), [Dimension::Catalog]));
    }

    public function test_saving_a_product_leaves_content_and_theme_alone(): void
    {
        $tenant = $this->shop();

        $this->makeProduct();

        $fresh = $tenant->fresh();
        $this->assertSame('1', $this->generations->stamp($fresh, [Dimension::Content]));
        $this->assertSame('1', $this->generations->stamp($fresh, [Dimension::Theme]));
    }

    public function test_deleting_a_product_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();
        $product = $this->makeProduct();

        $before = (int) $tenant->fresh()->page_gen_catalog;
        $product->delete();

        $this->assertGreaterThan($before, (int) $tenant->fresh()->page_gen_catalog);
    }

    public function test_saving_a_homepage_block_bumps_content(): void
    {
        $tenant = $this->shop();

        HomepageBlock::create([
            'type' => BlockType::Text,
            'position' => 1,
            'visible' => true,
            'payload' => ['html' => '<p>ahoj</p>'],
        ]);

        $this->assertSame('2', $this->generations->stamp($tenant->fresh(), [Dimension::Content]));
    }

    public function test_saving_the_theme_bumps_theme(): void
    {
        $tenant = $this->shop();

        TenantTheme::updateOrCreate(['tenant_id' => $tenant->id], ['primary_color' => '#112233']);

        $this->assertSame('2', $this->generations->stamp($tenant->fresh(), [Dimension::Theme]));
    }

    public function test_a_write_for_one_shop_does_not_bump_another(): void
    {
        $first = $this->shop();

        $second = Tenant::factory()->withDomain('druhy.droidshop')->create();
        $this->activateModule($second, 'products');

        $this->makeProduct();

        $this->assertSame('1', $this->generations->stamp($second->fresh(), [Dimension::Catalog]));
        $this->assertSame('2', $this->generations->stamp($first->fresh(), [Dimension::Catalog]));
    }
}
