<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Services\AttributeWriter;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The code list as the merchant manages it (wave 4.2, task B4).
 */
class AttributeAdminTest extends TestCase
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
        $this->activateModule($this->tenant, 'products');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        app(TenantContext::class)->set($this->tenant);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/products/vlastnosti'.$path;
    }

    public function test_the_owner_creates_an_attribute_and_a_value(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['name' => 'Barva'])
            ->assertRedirect();

        $attribute = ProductAttribute::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->post($this->url("/{$attribute->id}/hodnoty"), ['value' => 'Modrá'])
            ->assertRedirect();

        $this->assertSame('barva', $attribute->code);
        $this->assertSame('modra', $attribute->fresh()->values->first()->slug);
    }

    public function test_an_attribute_in_use_is_refused_with_a_message(): void
    {
        $attribute = app(AttributeWriter::class)->create(['name' => 'Barva']);
        $value = app(AttributeWriter::class)->addValue($attribute, ['value' => 'Modrá']);

        $product = app(ProductWriter::class)->create([
            'name' => 'Obraz',
            'price' => 42900,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]);
        app(AttributeWriter::class)->syncForProduct($product, [$value->id]);

        $this->actingAs($this->owner)
            ->delete($this->url("/{$attribute->id}"))
            ->assertSessionHasErrors('attribute');

        $this->assertDatabaseHas('product_attributes', ['id' => $attribute->id]);
    }

    public function test_another_shops_attribute_is_not_found(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $foreignId = app(TenantContext::class)->runAs(
            $other,
            fn () => app(AttributeWriter::class)->create(['name' => 'Barva'])->id,
        );

        $this->actingAs($this->owner)
            ->delete($this->url("/{$foreignId}"))
            ->assertNotFound();

        $this->assertDatabaseHas('product_attributes', ['id' => $foreignId]);
    }

    public function test_a_staff_member_without_the_permission_is_refused(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get($this->url())
            ->assertStatus(403);
    }

    public function test_the_product_form_saves_the_values_it_was_given(): void
    {
        $attribute = app(AttributeWriter::class)->create(['name' => 'Barva']);
        $blue = app(AttributeWriter::class)->addValue($attribute, ['value' => 'Modrá']);
        app(AttributeWriter::class)->addValue($attribute, ['value' => 'Černá']);

        $product = app(ProductWriter::class)->create([
            'name' => 'Obraz',
            'price' => 42900,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]);

        $this->actingAs($this->owner)->patch(
            "http://shop1.droidshop/admin/m/products/{$product->slug}",
            [
                'name' => 'Obraz',
                'price' => '429,00',
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'weight_g' => 0,
                'stock_policy' => Product::STOCK_POLICY_BACKORDER,
                'attribute_value_ids' => [$blue->id],
            ],
        )->assertSessionHasNoErrors();

        $this->assertSame([$blue->id], $product->fresh()->attributeValues->pluck('id')->all());
    }
}
