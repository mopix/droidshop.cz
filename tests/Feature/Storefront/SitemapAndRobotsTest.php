<?php

namespace Tests\Feature\Storefront;

use App\Core\Enums\TenantStatus;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Services\CategoryTree;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class SitemapAndRobotsTest extends TestCase
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

    private function seedShop(Tenant $tenant): void
    {
        $this->context->runAs($tenant, function () use ($tenant): void {
            app(CategoryTree::class)->create([
                'name' => 'Notebooky '.$tenant->id, 'slug' => 'notebooky-'.$tenant->id, 'is_visible' => true,
            ]);

            app(CategoryTree::class)->create([
                'name' => 'Skrytá', 'slug' => 'skryta-'.$tenant->id, 'is_visible' => false,
            ]);

            app(ProductWriter::class)->create([
                'name' => 'Notebook', 'slug' => 'notebook-'.$tenant->id,
                'price' => 1000_00, 'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            app(ProductWriter::class)->create([
                'name' => 'Draft', 'slug' => 'draft-'.$tenant->id,
                'price' => 1000_00, 'status' => Product::STATUS_DRAFT,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);
        });
    }

    public function test_sitemap_lists_only_what_a_customer_may_see(): void
    {
        $this->seedShop($this->tenantA);

        $response = $this->get('http://shop1.droidshop/sitemap.xml')->assertOk();

        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = $response->getContent();

        $this->assertStringContainsString('http://shop1.droidshop/produkt/notebook-'.$this->tenantA->id, $xml);
        $this->assertStringContainsString('http://shop1.droidshop/kategorie/notebooky-'.$this->tenantA->id, $xml);
        $this->assertStringNotContainsString('draft-', $xml);
        $this->assertStringNotContainsString('skryta-', $xml);
    }

    public function test_sitemap_does_not_leak_another_tenants_urls(): void
    {
        $this->seedShop($this->tenantA);
        $this->seedShop($this->tenantB);

        $xml = $this->get('http://shop2.droidshop/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString('notebook-'.$this->tenantA->id, $xml);
        $this->assertStringContainsString('notebook-'.$this->tenantB->id, $xml);
    }

    public function test_sitemap_is_valid_xml(): void
    {
        $this->seedShop($this->tenantA);

        $xml = simplexml_load_string(
            $this->get('http://shop1.droidshop/sitemap.xml')->getContent()
        );

        $this->assertNotFalse($xml);
        $this->assertGreaterThan(0, $xml->count());
    }

    public function test_robots_points_at_the_sitemap_and_keeps_private_paths_out(): void
    {
        $response = $this->get('http://shop1.droidshop/robots.txt')->assertOk();

        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $body = $response->getContent();

        $this->assertStringContainsString('Sitemap: http://shop1.droidshop/sitemap.xml', $body);
        $this->assertStringContainsString('Disallow: /admin/', $body);
        $this->assertStringContainsString('Disallow: /pokladna/', $body);
    }

    public function test_a_shop_that_is_not_trading_is_not_crawled(): void
    {
        $this->tenantA->update(['status' => TenantStatus::Suspended]);

        // The storefront gate answers 503 for a suspended shop, and that is
        // also the right answer for a crawler asking for robots.txt.
        $this->get('http://shop1.droidshop/robots.txt')->assertStatus(503);
    }
}
