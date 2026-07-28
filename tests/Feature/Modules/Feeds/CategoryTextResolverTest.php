<?php

namespace Tests\Feature\Modules\Feeds;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Feeds\Models\FeedCategoryMapping;
use Modules\Feeds\Models\ProductFeed;
use Modules\Feeds\Support\CategoryTextResolver;
use Tests\TestCase;

class CategoryTextResolverTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
        $this->tenant = Tenant::factory()->create();
    }

    /**
     * @return array{parent: Category, child: Category}
     */
    private function tree(): array
    {
        return $this->context->runAs($this->tenant, function () {
            $parent = Category::query()->create(['name' => 'Elektronika', 'slug' => 'elektronika']);
            $child = Category::query()->create([
                'name' => 'Klávesnice',
                'slug' => 'klavesnice',
                'parent_id' => $parent->id,
            ]);

            return ['parent' => $parent, 'child' => $child->fresh()];
        });
    }

    public function test_a_mapping_wins(): void
    {
        ['child' => $child] = $this->tree();

        $this->context->runAs($this->tenant, function () use ($child) {
            FeedCategoryMapping::query()->create([
                'category_id' => $child->id,
                'type' => ProductFeed::TYPE_HEUREKA,
                'category_text' => 'Elektronika | Počítače a kancelář | Klávesnice',
            ]);

            $this->assertSame(
                'Elektronika | Počítače a kancelář | Klávesnice',
                app(CategoryTextResolver::class)->for($child, ProductFeed::TYPE_HEUREKA),
            );
        });
    }

    public function test_without_a_mapping_it_falls_back_to_the_shops_own_tree(): void
    {
        ['child' => $child] = $this->tree();

        $this->context->runAs($this->tenant, function () use ($child) {
            $this->assertSame(
                'Elektronika | Klávesnice',
                app(CategoryTextResolver::class)->for($child, ProductFeed::TYPE_HEUREKA),
            );
        });
    }

    public function test_a_mapping_for_another_feed_is_not_used(): void
    {
        ['child' => $child] = $this->tree();

        $this->context->runAs($this->tenant, function () use ($child) {
            FeedCategoryMapping::query()->create([
                'category_id' => $child->id,
                'type' => ProductFeed::TYPE_ZBOZI,
                'category_text' => 'Zboží cesta',
            ]);

            $this->assertSame(
                'Elektronika | Klávesnice',
                app(CategoryTextResolver::class)->for($child, ProductFeed::TYPE_HEUREKA),
            );
        });
    }

    public function test_a_product_without_a_category_gets_an_empty_string(): void
    {
        $this->context->runAs($this->tenant, function () {
            $this->assertSame('', app(CategoryTextResolver::class)->for(null, ProductFeed::TYPE_HEUREKA));
        });
    }
}
