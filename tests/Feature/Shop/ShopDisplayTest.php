<?php

namespace Tests\Feature\Shop;

use App\Core\Shop\ShopSettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Categories\Models\Category;
use Modules\Categories\Services\CategoryTree;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The Zobrazení screen and what its switches do on the storefront (wave 3.6).
 */
class ShopDisplayTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');

        $this->artisan('modules:sync')->assertSuccessful();

        app(TenantContext::class)->forget();

        $this->tenant = Tenant::factory()->create(['name' => 'Nářadí Novák']);
        Domain::create([
            'tenant_id' => $this->tenant->id,
            'domain' => 'shop.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        foreach (['storefront', 'categories', 'products'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').$path;
    }

    /**
     * A page whose navigation can be read on its own.
     *
     * Not the homepage: its default block set includes a category grid the
     * merchant picked in the page builder, and that grid is deliberately left
     * alone by this switch — it is curated content, not navigation. Asserting
     * against the homepage would be asserting against the grid.
     */
    private function page(): TestResponse
    {
        return $this->get($this->url('/hledani'));
    }

    protected function save(array $data): void
    {
        app(TenantContext::class)->set($this->tenant);
        app(ShopSettingsService::class)->update($data);
        app(TenantContext::class)->forget();
    }

    private function asTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($this->tenant, $callback);
    }

    protected function category(string $name, ?Category $parent = null): Category
    {
        return $this->asTenant(fn () => app(CategoryTree::class)->create([
            'name' => $name,
            'is_visible' => true,
        ], $parent));
    }

    protected function publishProduct(string $name, Category $category): Product
    {
        return $this->asTenant(function () use ($name, $category) {
            $product = app(ProductWriter::class)->create([
                'name' => $name,
                'sku' => strtoupper($name),
                'price' => 1000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);
            app(ProductWriter::class)->syncCategories($product, [$category->id], $category->id);

            return $product;
        });
    }

    public function test_the_screen_renders(): void
    {
        $this->actingAs($this->owner)
            ->get($this->url('/admin/nastaveni/zobrazeni'))
            ->assertOk();
    }

    public function test_an_empty_category_is_hidden_only_when_the_switch_is_on(): void
    {
        // A stocked category alongside the empty one: with nothing published
        // anywhere the whole menu is kept on purpose (see the test below), so
        // an empty shop could not tell the two behaviours apart.
        $this->publishProduct('Kladivo', $this->category('Kladiva'));
        $this->category('Prázdná');

        $this->page()->assertOk()->assertSee('Prázdná');

        $this->save(['hide_empty_categories' => true]);

        $response = $this->page()->assertOk();
        $response->assertDontSee('Prázdná');
        $response->assertSee('Kladiva');
    }

    /**
     * The ordinary shape of a catalogue: everything is filed into leaves and
     * the roots hold nothing themselves. Counting a root's own products would
     * take out the entire top-level menu, and the merchant would think the
     * switch deleted their categories.
     */
    public function test_a_root_whose_products_sit_in_a_child_stays_visible(): void
    {
        $root = $this->category('Nářadí');
        $child = $this->category('Vrtačky', $root);
        $this->publishProduct('Vrtacka', $child);

        $this->save(['hide_empty_categories' => true]);

        $this->page()->assertOk()->assertSee('Nářadí');
    }

    public function test_a_root_with_its_own_products_stays_visible(): void
    {
        $root = $this->category('Kladiva');
        $this->publishProduct('Kladivo', $root);

        $this->save(['hide_empty_categories' => true]);

        $this->page()->assertOk()->assertSee('Kladiva');
    }

    /**
     * A shop that has categories but no products yet keeps its menu: hiding
     * everything reads as a broken shop, not an empty one.
     */
    public function test_a_shop_with_no_products_at_all_keeps_its_menu(): void
    {
        $this->category('Nářadí');

        $this->save(['hide_empty_categories' => true]);

        $this->page()->assertOk()->assertSee('Nářadí');
    }

    public function test_a_custom_empty_search_text_is_used(): void
    {
        $this->save(['empty_search_text' => 'Tohle u nás zatím nevedeme.']);

        $this->get($this->url('/hledani?q=neexistujici'))
            ->assertOk()
            ->assertSee('Tohle u nás zatím nevedeme.');
    }

    public function test_an_empty_setting_degrades_to_the_default_text(): void
    {
        $this->get($this->url('/hledani?q=neexistujici'))
            ->assertOk()
            ->assertSee('Nic jsme nenašli.', escape: false);
    }

    public function test_saving_bumps_the_page_cache(): void
    {
        $this->tenant->refresh();
        $before = $this->tenant->page_gen_catalog;

        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/zobrazeni'), ['hide_empty_categories' => true]);

        $this->assertGreaterThan($before, $this->tenant->fresh()->page_gen_catalog);
    }
}
