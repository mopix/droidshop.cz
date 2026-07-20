<?php

namespace Tests\Feature\Modules;

use App\Core\Routing\RedirectRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Exceptions\InvalidCategoryTree;
use Modules\Categories\Models\Category;
use Modules\Categories\Services\CategoryTree;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The category tree is the shop's navigation and a big part of its SEO. Two
 * things must hold whatever the admin does to it: it stays a tree (no cycles,
 * bounded depth), and no URL it ever published starts 404ing.
 */
class CategoryTreeTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private CategoryTree $tree;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tree = app(CategoryTree::class);
        $this->context = app(TenantContext::class);
        $this->tenant = Tenant::factory()->create();

        $this->activateModule($this->tenant, 'categories');
    }

    private function inShop(callable $callback): mixed
    {
        return $this->context->runAs($this->tenant, $callback);
    }

    private function make(string $name, ?Category $parent = null): Category
    {
        return $this->tree->create(['name' => $name], $parent);
    }

    public function test_a_root_category_gets_a_slug_and_depth_zero(): void
    {
        $this->inShop(function () {
            $category = $this->make('Elektronika a spotřebiče');

            $this->assertSame('elektronika-a-spotrebice', $category->slug);
            $this->assertSame(0, $category->depth);
            $this->assertNull($category->parent_id);
        });
    }

    public function test_a_slug_collision_gets_a_suffix(): void
    {
        $this->inShop(function () {
            $this->make('Knihy');
            $second = $this->make('Knihy');

            $this->assertSame('knihy-2', $second->slug);
        });
    }

    public function test_the_same_slug_may_exist_in_another_shop(): void
    {
        // Uniqueness is per tenant. A global unique index would let the first
        // shop to register "knihy" block every other shop on the platform.
        $other = Tenant::factory()->create();
        $this->activateModule($other, 'categories');

        $this->inShop(fn () => $this->make('Knihy'));
        $slug = $this->context->runAs($other, fn () => $this->make('Knihy')->slug);

        $this->assertSame('knihy', $slug);
    }

    public function test_a_child_records_its_path_and_depth(): void
    {
        $this->inShop(function () {
            $root = $this->make('Elektronika');
            $child = $this->make('Notebooky', $root);

            $this->assertSame(1, $child->depth);
            $this->assertSame("/{$root->id}/", $child->path);
        });
    }

    public function test_moving_a_subtree_rewrites_the_path_of_every_descendant(): void
    {
        $this->inShop(function () {
            $a = $this->make('A');
            $b = $this->make('B');
            $child = $this->make('Child', $a);
            $grandchild = $this->make('Grandchild', $child);

            $this->tree->move($child, $b);

            $this->assertSame(1, $child->fresh()->depth);
            $this->assertSame("/{$b->id}/", $child->fresh()->path);
            $this->assertSame(2, $grandchild->fresh()->depth);
            $this->assertSame("/{$b->id}/{$child->id}/", $grandchild->fresh()->path);
        });
    }

    public function test_a_category_cannot_be_moved_under_its_own_descendant(): void
    {
        $this->inShop(function () {
            $root = $this->make('Root');
            $child = $this->make('Child', $root);

            $this->expectException(InvalidCategoryTree::class);

            $this->tree->move($root, $child);
        });
    }

    public function test_a_category_cannot_be_its_own_parent(): void
    {
        $this->inShop(function () {
            $root = $this->make('Root');

            $this->expectException(InvalidCategoryTree::class);

            $this->tree->move($root, $root);
        });
    }

    public function test_the_tree_is_capped_at_four_levels(): void
    {
        $this->inShop(function () {
            $level = null;

            foreach (['1', '2', '3', '4'] as $name) {
                $level = $this->make($name, $level);
            }

            $this->expectException(InvalidCategoryTree::class);

            $this->make('5', $level);
        });
    }

    public function test_a_move_that_would_push_descendants_past_the_cap_is_refused(): void
    {
        // The moved node fits, its grandchildren would not. Checking only the
        // node itself is the classic way this rule leaks.
        $this->inShop(function () {
            $deep = $this->make('1');
            $deep2 = $this->make('2', $deep);
            $deep3 = $this->make('3', $deep2);

            $branch = $this->make('Branch');
            $branchChild = $this->make('BranchChild', $branch);

            $this->expectException(InvalidCategoryTree::class);

            $this->tree->move($deep, $branchChild);
        });
    }

    public function test_breadcrumbs_run_from_the_root_down(): void
    {
        $this->inShop(function () {
            $root = $this->make('Elektronika');
            $child = $this->make('Notebooky', $root);
            $leaf = $this->make('Herní', $child);

            $this->assertSame(
                ['Elektronika', 'Notebooky', 'Herní'],
                $this->tree->breadcrumbs($leaf)->pluck('name')->all()
            );
        });
    }

    public function test_descendants_are_found_without_recursion(): void
    {
        $this->inShop(function () {
            $root = $this->make('Root');
            $child = $this->make('Child', $root);
            $grandchild = $this->make('Grandchild', $child);
            $unrelated = $this->make('Unrelated');

            $ids = $this->tree->descendants($root)->pluck('id')->all();

            $this->assertEqualsCanonicalizing([$child->id, $grandchild->id], $ids);
            $this->assertNotContains($unrelated->id, $ids);
        });
    }

    public function test_renaming_the_slug_leaves_a_permanent_redirect(): void
    {
        $this->inShop(function () {
            $category = $this->make('Elektronika');

            $this->tree->update($category, ['slug' => 'spotrebice']);

            $this->assertSame(
                '/kategorie/spotrebice',
                app(RedirectRegistry::class)->resolve('/kategorie/elektronika')
            );
        });
    }

    public function test_renaming_only_the_name_does_not_touch_the_url(): void
    {
        // The slug is the shop's SEO asset. Editing a display name must not
        // silently rewrite a URL that is already indexed and linked.
        $this->inShop(function () {
            $category = $this->make('Elektronika');

            $this->tree->update($category, ['name' => 'Elektro']);

            $this->assertSame('elektronika', $category->fresh()->slug);
        });
    }

    public function test_categories_do_not_cross_between_shops(): void
    {
        $other = Tenant::factory()->create();
        $this->activateModule($other, 'categories');

        $this->inShop(fn () => $this->make('Tajná'));

        $this->assertSame(
            0,
            $this->context->runAs($other, fn () => Category::query()->count())
        );
    }

    public function test_new_categories_land_at_the_end_of_their_level(): void
    {
        $this->inShop(function () {
            $first = $this->make('První');
            $second = $this->make('Druhá');

            $this->assertLessThan($second->position, $first->position);
        });
    }
}
