<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Product dimensions (wave 3.8).
 *
 * A product knew only its weight, so a customer could learn its size only if
 * the merchant happened to write it into the description, and the carrier was
 * told nothing at all.
 */
class ProductDimensionsTest extends TestCase
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

        $this->tenant = Tenant::factory()->create(['vat_payer' => true]);
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
    }

    private function url(string $path): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').$path;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kladivo',
            'price' => '1210',
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
            'status' => Product::STATUS_ACTIVE,
            'weight_g' => 500,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
        ], $overrides);
    }

    private function publish(array $attributes = []): Product
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Kladivo',
            'sku' => 'KLADIVO',
            'price' => 121000,
            'status' => Product::STATUS_ACTIVE,
            'weight_g' => 500,
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
        ], $attributes)));
    }

    public function test_dimensions_are_saved(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload([
            'length_mm' => 200,
            'width_mm' => 150,
            'height_mm' => 80,
        ]))->assertRedirect();

        $product = app(TenantContext::class)->runAs($this->tenant, fn () => Product::query()->first());

        $this->assertSame(200, $product->length_mm);
        $this->assertSame(80, $product->height_mm);
    }

    public function test_dimensions_may_be_left_out(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload())
            ->assertRedirect();

        $product = app(TenantContext::class)->runAs($this->tenant, fn () => Product::query()->first());

        $this->assertNull($product->length_mm);
        $this->assertFalse($product->hasDimensions());
    }

    /**
     * Two metres is the ceiling; beyond that it is a typo, not a parcel.
     */
    public function test_an_absurd_dimension_is_refused(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload([
            'length_mm' => 999999,
        ]))->assertSessionHasErrors('length_mm');
    }

    public function test_a_customer_sees_the_dimensions(): void
    {
        $product = $this->publish(['length_mm' => 200, 'width_mm' => 150, 'height_mm' => 80]);

        $response = $this->get($this->url('/produkt/'.$product->slug))->assertOk();

        $response->assertSee('Parametry');
        $response->assertSee('20 × 15 × 8 cm');
    }

    /**
     * A table of dashes says less than no table.
     */
    public function test_a_product_without_dimensions_shows_no_dimension_row(): void
    {
        $product = $this->publish(['weight_g' => 0]);

        $this->get($this->url('/produkt/'.$product->slug))
            ->assertOk()
            ->assertDontSee('Parametry');
    }

    /**
     * Weight alone is still worth a parameters block — it was already stored
     * and never shown anywhere.
     */
    public function test_weight_alone_still_shows(): void
    {
        $product = $this->publish(['weight_g' => 500]);

        $this->get($this->url('/produkt/'.$product->slug))
            ->assertOk()
            ->assertSee('Parametry')
            ->assertSee('0,50 kg');
    }

    public function test_only_a_complete_set_counts(): void
    {
        $product = $this->publish(['length_mm' => 200, 'width_mm' => 150]);

        $this->assertFalse($product->hasDimensions());
        $this->assertNull($product->dimensionsLabel());
        $this->assertNull($product->catalogDimensionsMm());
    }
}
