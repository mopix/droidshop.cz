<?php

namespace Tests\Feature\Modules\Feeds;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\Categories\Models\Category;
use Modules\Feeds\Models\FeedCategoryMapping;
use Modules\Feeds\Models\ProductFeed;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class FeedAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        foreach (['categories', 'feeds'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/feeds'.$path;
    }

    public function test_the_screen_lists_both_feeds(): void
    {
        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Feeds/Index')
                ->has('feeds', 2)
                ->has('categories')
            );
    }

    public function test_an_owner_enables_a_feed(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url('/heureka'), ['enabled' => true, 'delivery_date' => 5])
            ->assertRedirect();

        $feed = $this->context->runAs($this->tenant, fn () => ProductFeed::query()->firstOrFail());

        $this->assertTrue($feed->enabled);
        $this->assertSame(5, $feed->deliveryDays());
    }

    public function test_a_category_mapping_is_saved(): void
    {
        $category = $this->context->runAs(
            $this->tenant,
            fn () => Category::query()->create(['name' => 'Klávesnice', 'slug' => 'klavesnice']),
        );

        $this->actingAs($this->owner)
            ->patch($this->url('/heureka/kategorie'), [
                'mappings' => [['category_id' => $category->id, 'category_text' => 'Elektronika | Klávesnice']],
            ])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($category) {
            $mapping = FeedCategoryMapping::query()->firstOrFail();

            $this->assertSame($category->id, $mapping->category_id);
            $this->assertSame('heureka', $mapping->type);
            $this->assertSame('Elektronika | Klávesnice', $mapping->category_text);
        });
    }

    public function test_an_emptied_mapping_is_removed(): void
    {
        $category = $this->context->runAs(
            $this->tenant,
            fn () => Category::query()->create(['name' => 'Klávesnice', 'slug' => 'klavesnice']),
        );

        $this->actingAs($this->owner)->patch($this->url('/heureka/kategorie'), [
            'mappings' => [['category_id' => $category->id, 'category_text' => 'Něco']],
        ]);

        $this->actingAs($this->owner)->patch($this->url('/heureka/kategorie'), [
            'mappings' => [['category_id' => $category->id, 'category_text' => '']],
        ]);

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => FeedCategoryMapping::query()->count()));
    }

    public function test_a_category_of_another_shop_is_not_saved(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $foreign = $this->context->runAs(
            $other,
            fn () => Category::query()->create(['name' => 'Cizí', 'slug' => 'cizi']),
        );

        $this->actingAs($this->owner)
            ->patch($this->url('/heureka/kategorie'), [
                'mappings' => [['category_id' => $foreign->id, 'category_text' => 'Cokoliv']],
            ]);

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => FeedCategoryMapping::query()->count()));
    }

    public function test_an_unknown_feed_type_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url('/google'), ['enabled' => true])
            ->assertNotFound();
    }

    public function test_a_signed_out_visitor_gets_nothing(): void
    {
        $this->get($this->url())->assertRedirect();
    }
}
