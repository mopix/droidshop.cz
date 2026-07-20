<?php

namespace Tests\Feature\Modules;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\Categories\Models\Category;
use Modules\Categories\Services\CategoryTree;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class CategoryAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'categories');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/categories'.$path;
    }

    private function make(string $name, ?Category $parent = null): Category
    {
        return $this->context->runAs(
            $this->tenant,
            fn () => app(CategoryTree::class)->create(['name' => $name], $parent)
        );
    }

    public function test_the_listing_renders_the_tree(): void
    {
        $root = $this->make('Elektronika');
        $this->make('Notebooky', $root);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Categories/Index')
                ->has('categories', 1)
                ->where('categories.0.name', 'Elektronika')
                ->has('categories.0.children', 1)
                ->where('categories.0.children.0.name', 'Notebooky')
            );
    }

    public function test_a_category_is_created_with_a_generated_slug(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['name' => 'Zahradní nábytek'])
            ->assertRedirect();

        $this->assertSame('zahradni-nabytek', $this->context->runAs(
            $this->tenant,
            fn () => Category::query()->value('slug')
        ));
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_a_slug_may_be_chosen_but_must_look_like_a_slug(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['name' => 'Knihy', 'slug' => 'Ne Platný Slug!'])
            ->assertSessionHasErrors('slug');
    }

    public function test_a_category_of_another_shop_cannot_be_edited(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'categories');

        $foreign = $this->context->runAs(
            $other,
            fn () => app(CategoryTree::class)->create(['name' => 'Cizí'])
        );

        // Route binding is scoped, so the row simply does not exist from here.
        $this->actingAs($this->owner)
            ->patch($this->url('/'.$foreign->slug), ['name' => 'Ukradeno'])
            ->assertNotFound();
    }

    public function test_renaming_the_slug_records_a_redirect(): void
    {
        $category = $this->make('Elektronika');

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$category->slug), ['name' => 'Elektronika', 'slug' => 'spotrebice'])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () {
            $this->assertDatabaseHas('redirects', [
                'from_path' => '/kategorie/elektronika',
                'to_path' => '/kategorie/spotrebice',
                'status' => 301,
            ]);
        });
    }

    public function test_a_move_under_a_descendant_is_rejected_with_a_message(): void
    {
        $root = $this->make('Root');
        $child = $this->make('Child', $root);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$root->slug.'/presun'), ['parent_id' => $child->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_deleting_a_category_with_children_requires_a_destination(): void
    {
        $root = $this->make('Root');
        $this->make('Child', $root);

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$root->slug))
            ->assertSessionHasErrors('move_to');
    }

    public function test_deleting_a_category_moves_its_children_to_the_chosen_destination(): void
    {
        $root = $this->make('Root');
        $child = $this->make('Child', $root);
        $target = $this->make('Target');

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$root->slug), ['move_to' => $target->id])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($root, $child, $target) {
            $this->assertDatabaseMissing('categories', ['id' => $root->id]);
            $this->assertSame($target->id, Category::query()->findOrFail($child->id)->parent_id);
        });
    }

    public function test_an_empty_category_is_deleted_without_a_destination(): void
    {
        $category = $this->make('Prázdná');

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$category->slug))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseMissing(
            'categories', ['id' => $category->id]
        ));
    }

    public function test_a_member_without_the_edit_permission_cannot_write(): void
    {
        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff', 'permissions' => ['categories.view'], 'joined_at' => now(),
        ]);

        $this->actingAs($staff)->get($this->url())->assertOk();
        $this->actingAs($staff)->post($this->url(), ['name' => 'Pokus'])->assertForbidden();
    }

    public function test_reordering_writes_the_new_positions(): void
    {
        $first = $this->make('První');
        $second = $this->make('Druhá');

        $this->actingAs($this->owner)
            ->post($this->url('/poradi'), ['parent_id' => null, 'ids' => [$second->id, $first->id]])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($first, $second) {
            $this->assertGreaterThan(
                Category::query()->findOrFail($second->id)->position,
                Category::query()->findOrFail($first->id)->position,
            );
        });
    }
}
