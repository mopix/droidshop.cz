<?php

namespace Tests\Feature\Storefront;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_empty_homepage_still_answers_ok(): void
    {
        $this->get('http://blockshop.droidshop/')
            ->assertOk()
            ->assertSee('Nabídka se právě připravuje');
    }
}
