<?php

namespace Tests\Feature\Storefront;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The payloads of the item-shaped homepage blocks (wave 4.2, task A1).
 *
 * A slider, a benefits strip, product tabs and a banner grid all carry a list
 * of items rather than a handful of fields, and the list is what has to be
 * bounded: a homepage nobody can finish downloading is stored whole by the
 * page cache and served to every visitor after that.
 */
class HomepageBlockPayloadTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function block(BlockType $type): HomepageBlock
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => HomepageBlock::create([
            'position' => 0,
            'type' => $type,
            'payload' => $type->defaultPayload(),
            'visible' => true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function save(HomepageBlock $block, array $payload): TestResponse
    {
        return $this->actingAs($this->owner)->patch(
            "http://shop1.droidshop/admin/m/storefront/homepage/blok/{$block->id}",
            ['payload' => $payload],
        );
    }

    /**
     * @return list<array{title: string, image_path: null, alt: string}>
     */
    private function slides(int $count): array
    {
        return array_map(fn (int $i): array => [
            'title' => "Slide {$i}",
            'subtitle' => null,
            'cta_label' => null,
            'cta_url' => null,
            'image_path' => null,
            'alt' => '',
        ], range(1, $count));
    }

    public function test_every_new_block_type_has_a_default_payload(): void
    {
        foreach ([BlockType::Slider, BlockType::UspStrip, BlockType::ProductTabs, BlockType::CategoryMosaic, BlockType::BannerGrid] as $type) {
            $this->assertNotSame([], $type->defaultPayload(), "{$type->value} has no default payload.");
        }
    }

    public function test_a_slider_holds_its_slides(): void
    {
        $block = $this->block(BlockType::Slider);

        $this->save($block, ['slides' => $this->slides(3)])->assertSessionHasNoErrors();

        $this->assertCount(3, $block->fresh()->payload['slides']);
    }

    public function test_a_slider_refuses_more_slides_than_a_visitor_would_ever_see(): void
    {
        $block = $this->block(BlockType::Slider);

        $this->save($block, ['slides' => $this->slides(9)])->assertSessionHasErrors('payload.slides');
    }

    public function test_a_slide_without_a_title_is_refused(): void
    {
        $block = $this->block(BlockType::Slider);

        $slides = $this->slides(2);
        $slides[1]['title'] = '';

        $this->save($block, ['slides' => $slides])->assertSessionHasErrors();
    }

    public function test_a_benefits_strip_refuses_an_icon_the_platform_does_not_have(): void
    {
        // A theme renders these as inline SVG from a fixed set. An unknown name
        // would render nothing at all, and an empty column in a row of four
        // reads as a broken page rather than as a missing icon.
        $block = $this->block(BlockType::UspStrip);

        $this->save($block, ['items' => [
            ['icon' => 'truck', 'title' => 'Doprava zdarma', 'subtitle' => 'nad 2500 Kč'],
            ['icon' => 'neexistujici-ikona', 'title' => 'Rychlé dodání', 'subtitle' => null],
        ]])->assertSessionHasErrors();
    }

    public function test_a_benefits_strip_wants_at_least_two_items(): void
    {
        $block = $this->block(BlockType::UspStrip);

        $this->save($block, ['items' => [
            ['icon' => 'truck', 'title' => 'Doprava zdarma', 'subtitle' => null],
        ]])->assertSessionHasErrors('payload.items');
    }

    public function test_product_tabs_are_bounded_too(): void
    {
        $block = $this->block(BlockType::ProductTabs);

        $tabs = array_map(fn (int $i): array => [
            'label' => "Záložka {$i}",
            'mode' => 'latest',
            'count' => 4,
            'category_id' => null,
            'product_ids' => [],
        ], range(1, 6));

        $this->save($block, ['heading' => 'Nabídka', 'tabs' => $tabs])->assertSessionHasErrors('payload.tabs');
    }

    public function test_a_banner_grid_needs_alt_text_on_every_banner(): void
    {
        // Same rule the single banner block already enforces: an <img> the
        // shop renders without alt text is a link a screen reader cannot name.
        $block = $this->block(BlockType::BannerGrid);

        $this->save($block, ['banners' => [
            ['image_path' => 'homepage/1-0.jpg', 'url' => null, 'alt' => 'Jarní kolekce'],
            ['image_path' => 'homepage/1-1.jpg', 'url' => null, 'alt' => ''],
        ]])->assertSessionHasErrors();
    }

    public function test_a_banner_url_is_still_checked(): void
    {
        $block = $this->block(BlockType::BannerGrid);

        $this->save($block, ['banners' => [
            ['image_path' => null, 'url' => 'javascript:alert(1)', 'alt' => 'X'],
            ['image_path' => null, 'url' => null, 'alt' => 'Y'],
        ]])->assertSessionHasErrors();
    }

    public function test_an_item_image_path_from_the_request_is_thrown_away(): void
    {
        // update() already strips a forged top-level image_path, but a slider
        // keeps one per slide and that guard never reached inside the list. A
        // payload naming any file on the public disk must not stick.
        $block = $this->block(BlockType::Slider);

        $slides = $this->slides(1);
        $slides[0]['image_path'] = 'theme/logo.png';
        $slides[0]['alt'] = 'Cizí soubor';

        $this->save($block, ['slides' => $slides])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('image_path', $block->fresh()->payload['slides'][0]);
    }

    public function test_a_stored_item_image_survives_an_edit_that_does_not_upload(): void
    {
        $block = $this->block(BlockType::Slider);
        app(TenantContext::class)->runAs($this->tenant, fn () => $block->update(['payload' => [
            'slides' => [[
                'title' => 'Původní',
                'subtitle' => null,
                'cta_label' => null,
                'cta_url' => null,
                'image_path' => 'homepage/'.$block->id.'-0.jpg',
                'alt' => 'Obrázek',
            ]],
        ]]));

        $slides = $this->slides(1);
        $slides[0]['title'] = 'Nový nadpis';
        $slides[0]['alt'] = 'Obrázek';

        $this->save($block, ['slides' => $slides])->assertSessionHasNoErrors();

        $fresh = $block->fresh()->payload['slides'][0];

        $this->assertSame('Nový nadpis', $fresh['title']);
        $this->assertSame('homepage/'.$block->id.'-0.jpg', $fresh['image_path']);
    }

    public function test_a_mosaic_takes_only_layouts_the_theme_can_draw(): void
    {
        $block = $this->block(BlockType::CategoryMosaic);

        $this->save($block, ['heading' => 'Kategorie', 'layout' => 'sikmo', 'category_ids' => []])
            ->assertSessionHasErrors('payload.layout');
    }

    public function test_an_upload_lands_on_the_item_it_was_sent_for(): void
    {
        Storage::fake('tenant_public');

        $block = $this->block(BlockType::Slider);

        $slides = $this->slides(2);
        $slides[1]['alt'] = 'Druhý obrázek';

        $this->actingAs($this->owner)->post(
            "http://shop1.droidshop/admin/m/storefront/homepage/blok/{$block->id}",
            [
                '_method' => 'patch',
                'payload' => ['slides' => $slides],
                'images' => [1 => UploadedFile::fake()->image('slide.jpg')],
            ],
        )->assertSessionHasNoErrors();

        $fresh = $block->fresh()->payload['slides'];

        $this->assertArrayNotHasKey('image_path', $fresh[0]);
        $this->assertSame("homepage/{$block->id}-1.jpg", $fresh[1]['image_path']);
    }
}
