<?php

namespace Tests\Feature\PageCache;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\Storage\FileStorage;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Categories\Services\CategoryTree;
use Modules\Pages\Models\Page;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductImageService;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
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

        // Image uploads go through FileStorage; nothing may reach a real disk.
        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);

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

        // shop() activates modules, which now bumps Theme itself (page cache,
        // wave 3.0: a switched-on module can change what the layout renders)
        // — so the baseline to compare against is whatever shop() left
        // behind, not a fixed '1'.
        $themeBefore = $this->generations->stamp($tenant->fresh(), [Dimension::Theme]);

        $this->makeProduct();

        $fresh = $tenant->fresh();
        $this->assertSame('1', $this->generations->stamp($fresh, [Dimension::Content]));
        $this->assertSame($themeBefore, $this->generations->stamp($fresh, [Dimension::Theme]));
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

        // Same reasoning as test_saving_a_product_leaves_content_and_theme_alone:
        // shop()'s module activations already bumped Theme, so the assertion
        // is "one more than that", not a fixed '2'.
        $before = (int) $this->generations->stamp($tenant->fresh(), [Dimension::Theme]);

        TenantTheme::updateOrCreate(['tenant_id' => $tenant->id], ['primary_color' => '#112233']);

        $this->assertSame((string) ($before + 1), $this->generations->stamp($tenant->fresh(), [Dimension::Theme]));
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

    /**
     * Review round 2: three DIMENSION_BY_MODEL entries (Page, Category,
     * ProductVariant) had no test at all, so a typo in the Page key would
     * silently misroute it to Dimension::Catalog with nothing catching it.
     * This asserts both sides: content moves, catalog does not.
     */
    public function test_saving_a_page_bumps_content_and_not_the_catalogue(): void
    {
        $tenant = $this->shop();

        Page::create([
            'slug' => 'o-nas',
            'title' => 'O nás',
            'body' => 'Text stránky.',
            'is_published' => true,
        ]);

        $fresh = $tenant->fresh();
        $this->assertSame('2', $this->generations->stamp($fresh, [Dimension::Content]));
        $this->assertSame('1', $this->generations->stamp($fresh, [Dimension::Catalog]));
    }

    public function test_saving_a_category_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();
        $this->activateModule($tenant, 'categories');

        app(CategoryTree::class)->create(['name' => 'Notebooky']);

        $this->assertSame('2', $this->generations->stamp($tenant->fresh(), [Dimension::Catalog]));
    }

    public function test_saving_a_product_variant_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();
        $product = $this->makeProduct();
        $option = app(VariantWriter::class)->addOption($product, 'Velikost');
        app(VariantWriter::class)->addValue($option, 'M');

        $before = (int) $tenant->fresh()->page_gen_catalog;

        app(VariantWriter::class)->generate($product);

        $this->assertGreaterThan($before, (int) $tenant->fresh()->page_gen_catalog);
    }

    /**
     * Review round 2, finding 1: ProductOption/ProductOptionValue render
     * straight into the cached product page (variant-picker.blade.php prints
     * axis and value labels) but were missing from DIMENSION_BY_MODEL and the
     * observer registration list.
     */
    public function test_renaming_a_product_option_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();
        $product = $this->makeProduct();
        $option = app(VariantWriter::class)->addOption($product, 'Barva');

        $before = (int) $tenant->fresh()->page_gen_catalog;

        app(VariantWriter::class)->renameOption($option, 'Barva tricka');

        $this->assertGreaterThan($before, (int) $tenant->fresh()->page_gen_catalog);
    }

    public function test_adding_an_option_value_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();
        $product = $this->makeProduct();
        $option = app(VariantWriter::class)->addOption($product, 'Velikost');

        $before = (int) $tenant->fresh()->page_gen_catalog;

        app(VariantWriter::class)->addValue($option, 'M');

        $this->assertGreaterThan($before, (int) $tenant->fresh()->page_gen_catalog);
    }

    public function test_deleting_an_option_value_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();
        $product = $this->makeProduct();
        $option = app(VariantWriter::class)->addOption($product, 'Velikost');
        $value = app(VariantWriter::class)->addValue($option, 'M');

        $before = (int) $tenant->fresh()->page_gen_catalog;

        app(VariantWriter::class)->deleteValue($value);

        $this->assertGreaterThan($before, (int) $tenant->fresh()->page_gen_catalog);
    }

    /**
     * Whole-branch review, finding 4: ProductImage was on no list at all, yet
     * an image is the product page gallery, og:image, the tile on every
     * product card in category/homepage/search listings and IMGURL in both
     * feeds. An upload showed up nowhere for ten minutes and in no feed for
     * an hour.
     */
    public function test_adding_an_image_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();
        $product = $this->makeProduct();

        $before = (int) $tenant->fresh()->page_gen_catalog;

        app(ProductImageService::class)->add($product, UploadedFile::fake()->image('foto.jpg'));

        $this->assertGreaterThan($before, (int) $tenant->fresh()->page_gen_catalog);
    }

    public function test_deleting_an_image_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();
        $product = $this->makeProduct();
        $image = app(ProductImageService::class)->add($product, UploadedFile::fake()->image('foto.jpg'));

        $before = (int) $tenant->fresh()->page_gen_catalog;

        app(ProductImageService::class)->remove($image);

        $this->assertGreaterThan($before, (int) $tenant->fresh()->page_gen_catalog);
    }

    /**
     * ProductImageService::reorder() writes positions through the query
     * builder in a loop, so no Eloquent event fires and the observer never
     * sees it — the same shape as CategoryTree::reorder(). Which image leads
     * the gallery and the listing tile depends on that order, so it bumps
     * once for the whole call, not once per row that moved.
     */
    public function test_reordering_images_bumps_the_catalogue_exactly_once(): void
    {
        $tenant = $this->shop();
        $product = $this->makeProduct();

        $images = app(ProductImageService::class);
        $a = $images->add($product, UploadedFile::fake()->image('a.jpg'));
        $b = $images->add($product, UploadedFile::fake()->image('b.jpg'));
        $c = $images->add($product, UploadedFile::fake()->image('c.jpg'));

        $before = (int) $tenant->fresh()->page_gen_catalog;

        $images->reorder($product, [$c->id, $b->id, $a->id]);

        $this->assertSame($before + 1, (int) $tenant->fresh()->page_gen_catalog);
    }

    /**
     * Review round 2, finding 2: CategoryTree::reorder() writes through the
     * query builder in a loop (Category::query()->whereKey($id)->update(...)),
     * so no Eloquent event fires and the observer never sees it — the same
     * shape as the stock write-off exception. reorder() bumps for itself,
     * once for the whole call, not once per row.
     */
    public function test_reordering_categories_bumps_the_catalogue_exactly_once(): void
    {
        $tenant = $this->shop();
        $this->activateModule($tenant, 'categories');

        $tree = app(CategoryTree::class);
        $a = $tree->create(['name' => 'A']);
        $b = $tree->create(['name' => 'B']);
        $c = $tree->create(['name' => 'C']);

        $before = (int) $tenant->fresh()->page_gen_catalog;

        $tree->reorder(null, [$c->id, $b->id, $a->id]);

        $this->assertSame($before + 1, (int) $tenant->fresh()->page_gen_catalog);
    }

    /**
     * Whole-branch review, finding 5, kept as the record of a premise that
     * did not hold: the static-page route was to gain `catalog` because "the
     * shared layout renders $navCategories". It does not — pages::show is a
     * standalone HTML document that never includes storefront::layouts.shop,
     * where both the header nav and the theme composer live. Nothing
     * catalogue-shaped reaches this page, so `catalog` would only re-render
     * every static page on every stock write-off.
     *
     * This test holds the premise still: the day this view moves onto the
     * shared layout it starts failing, which is the signal to add `catalog`
     * to the route's dimensions.
     */
    public function test_a_static_page_renders_no_catalogue_data(): void
    {
        $this->withoutVite();

        $tenant = $this->shop();
        $this->activateModule($tenant, 'categories');
        $this->activateModule($tenant, 'pages');

        app(CategoryTree::class)->create(['name' => 'Boty', 'is_visible' => true]);

        Page::query()->create([
            'slug' => 'o-nas',
            'title' => 'O nas',
            'body' => 'Text stranky.',
            'is_published' => true,
        ]);

        $this->get('http://obchod.droidshop/stranka/o-nas')
            ->assertOk()
            ->assertSee('Text stranky.')
            ->assertDontSee('Boty');
    }
}
