<?php

namespace Tests\Feature\PageCache;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Feeds\Models\ProductFeed;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Both the sitemap and the comparison-shop feeds used to be a plain
 * `Cache::remember` with a one-hour TTL and no way to bust it early. This
 * confirms the wave 3.0 generation stamp actually reaches both cache keys.
 *
 * `Product::factory()` does not exist for this model (no HasFactory trait,
 * no factory class) — products are created through `ProductWriter`, the
 * same as every other feature test that seeds the catalogue.
 */
class XmlOutputInvalidationTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');
        $this->activateModule($this->tenant, 'products');
    }

    private function createProduct(string $name, string $slug): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => $name,
            'slug' => $slug,
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));
    }

    public function test_a_new_product_shows_up_in_the_sitemap_without_waiting_for_the_ttl(): void
    {
        $this->get('http://obchod.droidshop/sitemap.xml')->assertOk();

        $product = $this->createProduct('Novinka dne', 'novinka-dne');

        // Before wave 3.0 this held the stale document for a full hour.
        $this->get('http://obchod.droidshop/sitemap.xml')
            ->assertOk()
            ->assertSee($product->slug, escape: false);
    }

    public function test_a_new_product_shows_up_in_the_feed_without_waiting_for_the_ttl(): void
    {
        $this->activateModule($this->tenant, 'feeds');

        $this->context->runAs($this->tenant, fn () => ProductFeed::query()->create([
            'type' => ProductFeed::TYPE_HEUREKA,
            'enabled' => true,
            'settings' => ['delivery_date' => 5],
        ]));

        $this->get('http://obchod.droidshop/feed/heureka.xml')->assertOk();

        $product = $this->createProduct('Nová klávesnice', 'nova-klavesnice');

        // Before wave 3.0 this held the stale document for a full hour.
        $this->get('http://obchod.droidshop/feed/heureka.xml')
            ->assertOk()
            ->assertSee($product->slug, escape: false);
    }
}
