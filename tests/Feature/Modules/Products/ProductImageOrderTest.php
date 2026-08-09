<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImage;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Reordering a product's images (wave 3.8).
 *
 * ProductImageService::reorder() and its route have existed since wave 1.2
 * and nothing ever called them, so the gallery order was whatever the upload
 * order happened to be and could not be changed.
 */
class ProductImageOrderTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        Storage::fake('tenant_public');

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

        foreach (['storefront', 'products', 'categories'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->product = app(TenantContext::class)->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Kladivo',
            'sku' => 'KLADIVO',
            'price' => 121000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
        ]));
    }

    private function url(string $path = ''): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').'/admin/m/products/'.$this->product->slug.$path;
    }

    /**
     * @return list<int>
     */
    private function upload(int $count = 3): array
    {
        $this->actingAs($this->owner)->post($this->url('/obrazky'), [
            'images' => array_map(
                fn (int $i) => UploadedFile::fake()->image("obrazek-{$i}.png", 800, 800),
                range(1, $count),
            ),
        ])->assertRedirect();

        return $this->currentOrder();
    }

    /**
     * @return list<int>
     */
    private function currentOrder(): array
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => ProductImage::query()
            ->where('product_id', $this->product->id)
            ->orderBy('position')
            ->pluck('id')
            ->all());
    }

    public function test_the_order_can_be_changed(): void
    {
        $ids = $this->upload();

        $reversed = array_reverse($ids);

        $this->actingAs($this->owner)
            ->post($this->url('/obrazky/poradi'), ['ids' => $reversed])
            ->assertRedirect();

        $this->assertSame($reversed, $this->currentOrder());
    }

    /**
     * Which image leads the gallery is decided by this order, so the change
     * has to be visible to a customer — not just in the admin.
     */
    public function test_the_new_order_reaches_the_storefront(): void
    {
        $ids = $this->upload(2);

        [$firstPath, $secondPath] = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => [ProductImage::find($ids[0])->path, ProductImage::find($ids[1])->path],
        );

        // Read off the thumbnail list, not off the whole page: the main image
        // is printed above it as well, and it does not move when the order
        // does — reordering changes the gallery, not which image leads.
        $thumbnails = function () use ($firstPath): array {
            $html = $this->get(
                'http://shop.'.config('tenancy.platform_domain').'/produkt/'.$this->product->slug
            )->assertOk()->getContent();

            preg_match_all('/data-gallery-thumb="([^"]+)"/', $html, $matches);

            return array_map(
                fn (string $url): string => basename($url) === basename($firstPath) ? 'first' : 'second',
                $matches[1],
            );
        };

        // Asserted before as well as after, or the test could pass on a page
        // that never changed.
        $this->assertSame(['first', 'second'], $thumbnails());

        $this->actingAs($this->owner)
            ->post($this->url('/obrazky/poradi'), ['ids' => array_reverse($ids)]);

        $this->assertSame(['second', 'first'], $thumbnails());
    }

    /**
     * The reorder writes through the query builder, so no Eloquent event
     * fires and PageCacheObserver never sees it. Without its own bump the new
     * order would show up ten minutes later.
     */
    public function test_reordering_bumps_the_page_cache(): void
    {
        $ids = $this->upload(2);

        $this->tenant->refresh();
        $before = $this->tenant->page_gen_catalog;

        $this->actingAs($this->owner)->post($this->url('/obrazky/poradi'), ['ids' => array_reverse($ids)]);

        $this->assertGreaterThan($before, $this->tenant->fresh()->page_gen_catalog);
    }

    /**
     * An id from another product must not be dragged into this one's gallery.
     */
    public function test_an_id_from_elsewhere_is_ignored(): void
    {
        $ids = $this->upload(2);

        $this->actingAs($this->owner)
            ->post($this->url('/obrazky/poradi'), ['ids' => [$ids[1], $ids[0], 999999]])
            ->assertRedirect();

        $this->assertSame([$ids[1], $ids[0]], $this->currentOrder());
    }

    public function test_a_member_without_the_edit_permission_cannot_reorder(): void
    {
        $ids = $this->upload(2);

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => ['products.view'],
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)
            ->post($this->url('/obrazky/poradi'), ['ids' => array_reverse($ids)])
            ->assertForbidden();

        $this->assertSame($ids, $this->currentOrder());
    }
}
