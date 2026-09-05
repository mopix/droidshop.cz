<?php

namespace Tests\Feature\Storefront;

use App\Core\Enums\TenantStatus;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class HomepageAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeShopWithOwner(string $subdomain): array
    {
        $tenant = Tenant::factory()->withDomain($subdomain.'.droidshop')->create();
        $this->activateModule($tenant, 'storefront');

        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    private function url(Tenant $tenant, string $path = ''): string
    {
        $domain = $tenant->primaryDomain?->domain;

        return 'http://'.$domain.'/admin/m/storefront/homepage'.$path;
    }

    private function block(Tenant $tenant, BlockType $type, array $payload, int $position = 0): HomepageBlock
    {
        return $this->context->runAs($tenant, fn () => HomepageBlock::create([
            'position' => $position,
            'type' => $type,
            'payload' => $payload,
            'visible' => true,
        ]));
    }

    private function freshPayload(Tenant $tenant, HomepageBlock $block): array
    {
        return $this->context->runAs(
            $tenant,
            fn () => HomepageBlock::query()->findOrFail($block->id)->payload,
        );
    }

    public function test_an_owner_can_add_a_block(): void
    {
        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        $this->actingAs($owner)
            ->post($this->url($tenant, '/blok'), ['type' => 'text'])
            ->assertRedirect();

        $count = $this->context->runAs($tenant, fn () => HomepageBlock::count());

        $this->assertSame(1, $count);
    }

    public function test_a_block_belonging_to_another_shop_is_not_found(): void
    {
        [$tenantA, $ownerA] = $this->makeShopWithOwner('shopa');
        [$tenantB] = $this->makeShopWithOwner('shopb');

        $foreignBlock = $this->block($tenantB, BlockType::Text, ['heading' => null, 'html' => 'cizí']);

        $this->actingAs($ownerA)
            ->delete($this->url($tenantA, '/blok/'.$foreignBlock->id))
            ->assertNotFound();
    }

    public function test_a_javascript_hero_cta_url_is_rejected(): void
    {
        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        $hero = $this->block($tenant, BlockType::Hero, [
            'title' => 'Vítejte', 'subtitle' => null, 'cta_label' => null, 'cta_url' => null, 'image_path' => null,
        ]);

        $this->actingAs($owner)
            ->patch($this->url($tenant, '/blok/'.$hero->id), [
                'payload' => ['title' => 'x', 'cta_label' => 'Klik', 'cta_url' => 'javascript:alert(1)'],
            ])
            ->assertSessionHasErrors('payload.cta_url');
    }

    public function test_a_text_block_scripts_are_stripped_on_save(): void
    {
        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        $text = $this->block($tenant, BlockType::Text, ['heading' => null, 'html' => '']);

        $this->actingAs($owner)
            ->patch($this->url($tenant, '/blok/'.$text->id), [
                'payload' => ['heading' => null, 'html' => '<p>ok</p><script>alert(1)</script>'],
            ])
            ->assertRedirect();

        $payload = $this->freshPayload($tenant, $text);

        $this->assertStringNotContainsString('<script>', $payload['html']);
        $this->assertStringContainsString('<p>ok</p>', $payload['html']);
    }

    public function test_move_up_swaps_positions_with_the_previous_block(): void
    {
        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        $first = $this->block($tenant, BlockType::Text, ['heading' => null, 'html' => 'první'], 0);
        $second = $this->block($tenant, BlockType::Text, ['heading' => null, 'html' => 'druhý'], 1);

        $this->actingAs($owner)
            ->patch($this->url($tenant, '/blok/'.$second->id.'/presun'), ['direction' => 'up'])
            ->assertRedirect();

        $this->context->runAs($tenant, function () use ($first, $second) {
            $this->assertSame(0, HomepageBlock::query()->findOrFail($second->id)->position);
            $this->assertSame(1, HomepageBlock::query()->findOrFail($first->id)->position);
        });
    }

    public function test_writes_are_blocked_for_a_suspended_tenant(): void
    {
        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['status' => TenantStatus::Suspended]);
        $this->activateModule($tenant, 'storefront');

        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($owner)
            ->post($this->url($tenant, '/blok'), ['type' => 'text'])
            ->assertStatus(503);
    }

    public function test_the_thirty_first_block_is_rejected(): void
    {
        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        for ($i = 0; $i < 30; $i++) {
            $this->block($tenant, BlockType::Text, ['heading' => null, 'html' => (string) $i], $i);
        }

        $this->actingAs($owner)
            ->post($this->url($tenant, '/blok'), ['type' => 'text'])
            ->assertSessionHasErrors('type');
    }

    public function test_a_second_hero_block_is_rejected(): void
    {
        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        $this->block($tenant, BlockType::Hero, [
            'title' => 'Vítejte', 'subtitle' => null, 'cta_label' => null, 'cta_url' => null, 'image_path' => null, 'alt' => null,
        ]);

        $this->actingAs($owner)
            ->post($this->url($tenant, '/blok'), ['type' => 'hero'])
            ->assertSessionHasErrors('type');

        $count = $this->context->runAs($tenant, fn () => HomepageBlock::query()->where('type', BlockType::Hero)->count());

        $this->assertSame(1, $count);
    }

    public function test_a_banner_with_an_uploaded_image_but_empty_alt_is_rejected(): void
    {
        Storage::fake(FileStorage::PUBLIC_DISK);

        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        $banner = $this->block($tenant, BlockType::Banner, [
            'url' => null, 'image_path' => null, 'alt' => '',
        ]);

        $this->actingAs($owner)
            ->patch($this->url($tenant, '/blok/'.$banner->id), [
                'payload' => ['url' => null, 'alt' => ''],
                'image' => UploadedFile::fake()->image('b.png'),
            ])
            ->assertSessionHasErrors('payload.alt');
    }

    public function test_an_uploaded_image_overrides_a_spoofed_payload_image_path(): void
    {
        Storage::fake(FileStorage::PUBLIC_DISK);

        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        $banner = $this->block($tenant, BlockType::Banner, [
            'url' => null, 'image_path' => null, 'alt' => '',
        ]);

        $this->actingAs($owner)
            ->patch($this->url($tenant, '/blok/'.$banner->id), [
                'payload' => ['url' => null, 'alt' => 'x', 'image_path' => 'forged/evil.png'],
                'image' => UploadedFile::fake()->image('b.png'),
            ])
            ->assertRedirect();

        $payload = $this->freshPayload($tenant, $banner);

        $this->assertSame("homepage/{$banner->id}.png", $payload['image_path']);
        $this->assertNotSame('forged/evil.png', $payload['image_path']);
    }

    public function test_a_spoofed_payload_image_path_without_a_file_upload_is_ignored(): void
    {
        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        $banner = $this->block($tenant, BlockType::Banner, [
            'url' => null, 'image_path' => 'homepage/existing.png', 'alt' => '',
        ]);

        $this->actingAs($owner)
            ->patch($this->url($tenant, '/blok/'.$banner->id), [
                'payload' => ['url' => null, 'alt' => 'x', 'image_path' => 'forged/evil.png'],
            ])
            ->assertRedirect();

        $payload = $this->freshPayload($tenant, $banner);

        $this->assertSame('homepage/existing.png', $payload['image_path']);
        $this->assertNotSame('forged/evil.png', $payload['image_path']);
    }

    public function test_an_svg_image_upload_is_rejected(): void
    {
        [$tenant, $owner] = $this->makeShopWithOwner('shop1');

        $banner = $this->block($tenant, BlockType::Banner, [
            'url' => null, 'image_path' => null, 'alt' => '',
        ]);

        $this->actingAs($owner)
            ->patch($this->url($tenant, '/blok/'.$banner->id), [
                'payload' => ['url' => null, 'alt' => 'x'],
                'image' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('image');
    }

    public function test_the_editor_offers_every_block_type(): void
    {
        // The picker is built from what the server sends, so a type added to
        // the enum without reaching this list would exist and be unreachable.
        [$tenant, $owner] = $this->makeShopWithOwner('shop1');
        $this->withoutVite();

        $this->actingAs($owner)
            ->get($this->url($tenant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Storefront/Homepage')
                ->where('blockTypes', array_map(
                    fn (BlockType $type): string => $type->value,
                    BlockType::cases(),
                ))
                ->etc()
            );
    }
}
