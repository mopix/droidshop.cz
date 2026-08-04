<?php

namespace Tests\Feature\PageCache;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Feeds\Models\ProductFeed;
use Modules\Pages\Models\Page;
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProduct(string $name, string $slug, array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => $name,
            'slug' => $slug,
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
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

    /**
     * Review round 2: the sitemap also lists Page::pageEntries(), and Page is
     * bumped under Dimension::Content, not Catalog — a stamp built only from
     * Catalog would leave a freshly published page invisible to the sitemap
     * for the full TTL even though nothing about the catalogue changed.
     */
    public function test_a_new_page_shows_up_in_the_sitemap_without_waiting_for_the_ttl(): void
    {
        $this->activateModule($this->tenant, 'pages');

        $this->get('http://obchod.droidshop/sitemap.xml')->assertOk();

        $this->context->runAs($this->tenant, fn () => Page::query()->create([
            'slug' => 'o-nas',
            'title' => 'O nás',
            'body' => 'Text stránky.',
            'is_published' => true,
        ]));

        // Before this fix the stamp never moved for a page-only change.
        $this->get('http://obchod.droidshop/sitemap.xml')
            ->assertOk()
            ->assertSee('/stranka/o-nas', escape: false);
    }

    /**
     * Review round 2: FeedAdminController used to bump the tenant's entire
     * Catalog generation to invalidate one feed, which also evicted every
     * product/category page and the sitemap. It now forgets exactly the one
     * cache key that changed. This proves the admin save still refreshes the
     * feed immediately — the narrower mechanism must not regress the outcome
     * the wider one used to guarantee.
     */
    public function test_editing_feed_settings_in_admin_refreshes_the_feed_without_waiting_for_the_ttl(): void
    {
        $this->activateModule($this->tenant, 'feeds');

        $owner = User::factory()->create();
        $this->tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->context->runAs($this->tenant, fn () => ProductFeed::query()->create([
            'type' => ProductFeed::TYPE_HEUREKA,
            'enabled' => true,
            'settings' => ['delivery_date' => 5],
        ]));

        // Out of stock so the feed actually prints the feed's configured
        // delivery_date instead of the "ships today" 0 it uses when the
        // product is available (see FeedItemBuilder::productItem()).
        $this->createProduct('Klávesnice', 'klavesnice', ['stock_tracked' => true, 'stock_qty' => 0]);

        $this->get('http://obchod.droidshop/feed/heureka.xml')
            ->assertOk()
            ->assertSee('<DELIVERY_DATE>5</DELIVERY_DATE>', escape: false);

        $this->actingAs($owner)
            ->patch('http://obchod.droidshop/admin/m/feeds/heureka', [
                'enabled' => true,
                'delivery_date' => 9,
            ])
            ->assertRedirect();

        // Before this fix the forget() call built a key that never matched
        // anything, so this would still read 5 for a full hour.
        $this->get('http://obchod.droidshop/feed/heureka.xml')
            ->assertOk()
            ->assertSee('<DELIVERY_DATE>9</DELIVERY_DATE>', escape: false);
    }
}
