<?php

namespace Tests\Feature\Storefront;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The storefront homepage rendered from the tenant's homepage_blocks.
 */
class HomepageBlocksRenderTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('blockshop.droidshop')->create(['name' => 'Block Shop']);

        $this->activateModule($this->tenant, 'storefront');
    }

    /**
     * @param  list<array{type: BlockType, payload: array, visible?: bool}>  $blocks
     */
    private function seedBlocks(array $blocks): void
    {
        $this->context->runAs($this->tenant, function () use ($blocks): void {
            foreach ($blocks as $i => $block) {
                HomepageBlock::create([
                    'position' => $i,
                    'type' => $block['type'],
                    'payload' => $block['payload'],
                    'visible' => $block['visible'] ?? true,
                ]);
            }
        });
    }

    public function test_renders_a_text_block_into_raw_html(): void
    {
        $this->seedBlocks([
            ['type' => BlockType::Text, 'payload' => ['heading' => 'O nás', 'html' => '<p>Ahoj</p>']],
        ]);

        $this->get('http://blockshop.droidshop/')
            ->assertOk()
            ->assertSee('O nás')
            ->assertSee('<p>Ahoj</p>', false);
    }

    public function test_does_not_render_hidden_blocks(): void
    {
        $this->seedBlocks([
            ['type' => BlockType::Text, 'payload' => ['html' => 'SKRYTO'], 'visible' => false],
        ]);

        $this->get('http://blockshop.droidshop/')->assertOk()->assertDontSee('SKRYTO');
    }

    public function test_product_row_is_skipped_when_the_products_module_is_off(): void
    {
        // Deliberately not activating 'products': the block must be dropped,
        // not crash the page.
        $this->seedBlocks([
            ['type' => BlockType::ProductRow, 'payload' => ['heading' => 'Novinky', 'mode' => 'latest', 'count' => 8, 'product_ids' => []]],
        ]);

        $this->get('http://blockshop.droidshop/')->assertOk()->assertDontSee('Novinky');
    }

    public function test_category_grid_is_skipped_when_the_categories_module_is_off(): void
    {
        $this->seedBlocks([
            ['type' => BlockType::CategoryGrid, 'payload' => ['heading' => 'Kategorie', 'category_ids' => []]],
        ]);

        $this->get('http://blockshop.droidshop/')->assertOk()->assertDontSee('Kategorie');
    }

    public function test_hero_block_renders_title_and_cta(): void
    {
        $this->seedBlocks([
            ['type' => BlockType::Hero, 'payload' => [
                'title' => 'Vítejte',
                'subtitle' => 'Ahoj světe',
                'cta_label' => 'Nakupovat',
                'cta_url' => '/kategorie/vse',
                'image_path' => null,
            ]],
        ]);

        $response = $this->get('http://blockshop.droidshop/')->assertOk();

        $response->assertSee('Vítejte')
            ->assertSee('Ahoj světe')
            ->assertSee('Nakupovat')
            ->assertSee('href="/kategorie/vse"', false);
    }

    public function test_hero_block_renders_its_image_with_alt(): void
    {
        $this->seedBlocks([
            ['type' => BlockType::Hero, 'payload' => [
                'title' => 'Vítejte',
                'subtitle' => null,
                'cta_label' => null,
                'cta_url' => null,
                'image_path' => 'homepage/1.png',
                'alt' => 'Úvodní fotka obchodu',
            ]],
        ]);

        $html = $this->get('http://blockshop.droidshop/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<img[^>]*alt="Úvodní fotka obchodu"[^>]*>/', $html);
    }

    public function test_blocks_render_in_position_order(): void
    {
        $this->seedBlocks([
            ['type' => BlockType::Text, 'payload' => ['html' => 'Druhy blok']],
            ['type' => BlockType::Text, 'payload' => ['html' => 'Prvni blok']],
        ]);

        // Swap explicit positions so the second created block renders first.
        $this->context->runAs($this->tenant, function (): void {
            $blocks = HomepageBlock::query()->orderBy('id')->get();
            $blocks[0]->update(['position' => 5]);
            $blocks[1]->update(['position' => 1]);
        });

        $html = $this->get('http://blockshop.droidshop/')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Druhy blok'), strpos($html, 'Prvni blok'));
    }

    public function test_banner_with_image_and_url_wraps_img_in_an_accessible_link(): void
    {
        $this->seedBlocks([
            ['type' => BlockType::Banner, 'payload' => [
                'image_path' => 'banners/leto.jpg',
                'url' => '/kategorie/leto',
                'alt' => '',
            ]],
        ]);

        $html = $this->get('http://blockshop.droidshop/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a[^>]*href="\/kategorie\/leto"[^>]*aria-label="[^"]+"[^>]*>\s*<img[^>]*>/s', $html);
    }

    public function test_banner_with_image_and_no_url_renders_img_without_a_link(): void
    {
        $this->seedBlocks([
            ['type' => BlockType::Banner, 'payload' => [
                'image_path' => 'banners/leto.jpg',
                'url' => null,
                'alt' => 'Letní kolekce',
            ]],
        ]);

        $response = $this->get('http://blockshop.droidshop/')->assertOk();
        $html = $response->getContent();

        $response->assertSee('Letní kolekce', false);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*>\s*<img[^>]*banners\/leto\.jpg/s', $html);
    }

    public function test_two_product_rows_sharing_a_heading_get_distinct_ids(): void
    {
        $this->activateModule($this->tenant, 'products');

        $this->seedBlocks([
            ['type' => BlockType::ProductRow, 'payload' => ['heading' => 'Novinky', 'mode' => 'latest', 'count' => 8, 'product_ids' => []]],
            ['type' => BlockType::ProductRow, 'payload' => ['heading' => 'Novinky', 'mode' => 'latest', 'count' => 8, 'product_ids' => []]],
        ]);

        $ids = $this->context->runAs($this->tenant, fn () => HomepageBlock::query()->orderBy('id')->pluck('id'));

        $html = $this->get('http://blockshop.droidshop/')->assertOk()->getContent();

        $this->assertStringContainsString('row-heading-'.$ids[0], $html);
        $this->assertStringContainsString('row-heading-'.$ids[1], $html);
        $this->assertSame(1, substr_count($html, 'id="row-heading-'.$ids[0].'"'));
        $this->assertSame(1, substr_count($html, 'id="row-heading-'.$ids[1].'"'));
    }

    public function test_empty_homepage_still_answers_ok(): void
    {
        $this->get('http://blockshop.droidshop/')
            ->assertOk()
            ->assertSee('Nabídka se právě připravuje');
    }

    public function test_a_slider_puts_every_slide_in_the_html(): void
    {
        // Not just the first one: the dots are anchors and the track scrolls,
        // which is what makes the block work with no JavaScript at all.
        $this->seedBlocks([[
            'type' => BlockType::Slider,
            'payload' => ['slides' => [
                ['title' => 'První slide', 'subtitle' => null, 'cta_label' => null, 'cta_url' => null, 'image_path' => null, 'alt' => ''],
                ['title' => 'Druhý slide', 'subtitle' => null, 'cta_label' => null, 'cta_url' => null, 'image_path' => null, 'alt' => ''],
                ['title' => 'Třetí slide', 'subtitle' => null, 'cta_label' => null, 'cta_url' => null, 'image_path' => null, 'alt' => ''],
            ]],
        ]]);

        $this->get('http://blockshop.droidshop/')
            ->assertOk()
            ->assertSee('První slide')
            ->assertSee('Druhý slide')
            ->assertSee('Třetí slide');
    }

    public function test_a_benefits_strip_renders_its_items(): void
    {
        $this->seedBlocks([[
            'type' => BlockType::UspStrip,
            'payload' => ['items' => [
                ['icon' => 'truck', 'title' => 'Doprava zdarma', 'subtitle' => 'nad 2500 Kč'],
                ['icon' => 'clock', 'title' => 'Rychlé dodání', 'subtitle' => 'do pár dní'],
            ]],
        ]]);

        $this->get('http://blockshop.droidshop/')
            ->assertOk()
            ->assertSee('Doprava zdarma')
            ->assertSee('nad 2500 Kč')
            ->assertSee('Rychlé dodání');
    }

    public function test_product_tabs_open_the_first_tab_and_leave_the_rest_as_links(): void
    {
        $this->activateModule($this->tenant, 'products');

        $this->seedBlocks([[
            'type' => BlockType::ProductTabs,
            'payload' => [
                'heading' => 'Nabídka',
                'tabs' => [
                    ['label' => 'Obrazy', 'mode' => 'latest', 'count' => 4, 'category_id' => null, 'product_ids' => []],
                    ['label' => 'Tapety', 'mode' => 'latest', 'count' => 4, 'category_id' => null, 'product_ids' => []],
                ],
            ],
        ]]);

        $response = $this->get('http://blockshop.droidshop/')->assertOk();

        $response->assertSee('Obrazy');
        $response->assertSee('Tapety');
        $response->assertSee('?zalozka=2', false);
    }

    public function test_an_out_of_range_tab_falls_back_to_the_first(): void
    {
        // A stale link or a crawler guessing at parameters must see goods, not
        // an empty section.
        $this->activateModule($this->tenant, 'products');

        $this->seedBlocks([[
            'type' => BlockType::ProductTabs,
            'payload' => [
                'heading' => 'Nabídka',
                'tabs' => [
                    ['label' => 'Obrazy', 'mode' => 'latest', 'count' => 4, 'category_id' => null, 'product_ids' => []],
                    ['label' => 'Tapety', 'mode' => 'latest', 'count' => 4, 'category_id' => null, 'product_ids' => []],
                ],
            ],
        ]]);

        $this->get('http://blockshop.droidshop/?zalozka=99')
            ->assertOk()
            ->assertSee('Obrazy');
    }

    public function test_a_category_mosaic_renders_its_categories(): void
    {
        $this->activateModule($this->tenant, 'categories');

        $category = $this->context->runAs($this->tenant, fn () => Category::create([
            'name' => 'Obrazy',
            'slug' => 'obrazy',
            'is_visible' => true,
            'position' => 0,
        ]));

        $this->seedBlocks([[
            'type' => BlockType::CategoryMosaic,
            'payload' => ['heading' => 'Kategorie', 'layout' => '1-2-1', 'category_ids' => [$category->id]],
        ]]);

        $this->get('http://blockshop.droidshop/')
            ->assertOk()
            ->assertSee('Kategorie')
            ->assertSee('Obrazy');
    }
}
