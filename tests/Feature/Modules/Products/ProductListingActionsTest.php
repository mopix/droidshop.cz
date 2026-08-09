<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Services\CategoryTree;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * What the listing lets a merchant do without opening a product (wave 3.12):
 * change its status, and put it in the bin.
 */
class ProductListingActionsTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        Domain::create([
            'tenant_id' => $this->tenant->id,
            'domain' => 'shop.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->artisan('modules:sync')->assertSuccessful();

        foreach (['products', 'categories'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path = ''): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').'/admin/m/products'.$path;
    }

    private function product(array $attributes = []): Product
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Kladivo',
            'sku' => 'KLADIVO',
            'price' => 121000,
            'status' => Product::STATUS_DRAFT,
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
        ], $attributes)));
    }

    public function test_the_status_can_be_changed_from_the_listing(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug.'/stav'), ['status' => Product::STATUS_ACTIVE])
            ->assertRedirect();

        $this->assertSame(Product::STATUS_ACTIVE, $product->fresh()->status);
    }

    /**
     * The listing carries none of the other fields, so this endpoint must not
     * be able to blank them — which is exactly what sending a half-filled
     * product through the full update would do.
     */
    public function test_changing_the_status_leaves_everything_else_alone(): void
    {
        $product = $this->product([
            'short_description' => 'Kladivo, které vydrží',
            'sale_price' => 99000,
            'weight_g' => 500,
        ]);

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug.'/stav'), ['status' => Product::STATUS_HIDDEN]);

        $fresh = $product->fresh();

        $this->assertSame('Kladivo, které vydrží', $fresh->short_description);
        $this->assertSame(99000, $fresh->sale_price->amount);
        $this->assertSame(500, $fresh->weight_g);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug.'/stav'), ['status' => 'smazano'])
            ->assertSessionHasErrors('status');

        $this->assertSame(Product::STATUS_DRAFT, $product->fresh()->status);
    }

    public function test_a_member_without_the_edit_permission_cannot_change_it(): void
    {
        $product = $this->product();

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => ['products.view'],
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)
            ->patch($this->url('/'.$product->slug.'/stav'), ['status' => Product::STATUS_ACTIVE])
            ->assertForbidden();

        $this->assertSame(Product::STATUS_DRAFT, $product->fresh()->status);
    }

    /**
     * The bin, not the shredder: an order that contains this product has to go
     * on making sense, which is why the row is soft-deleted.
     */
    public function test_deleting_from_the_listing_only_soft_deletes(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$product->slug))
            ->assertRedirect();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_the_listing_carries_the_short_description_and_the_categories(): void
    {
        $product = $this->product(['short_description' => 'Kladivo, které vydrží']);

        app(TenantContext::class)->runAs($this->tenant, function () use ($product) {
            $category = app(CategoryTree::class)->create(['name' => 'Nářadí', 'is_visible' => true]);
            app(ProductWriter::class)->syncCategories($product, [$category->id], $category->id);
        });

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.short_description', 'Kladivo, které vydrží')
                ->where('products.data.0.categories.0.name', 'Nářadí'));
    }
}
