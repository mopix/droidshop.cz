<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Exceptions\AttributeInUse;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Models\ProductAttributeValue;
use Modules\Products\Services\AttributeWriter;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

/**
 * Descriptive properties of goods and their code lists (wave 4.2, task B1).
 *
 * A filter is only as good as the values behind it, which is why these are a
 * code list and not free text: "tmavě modrá" and "tmavomodrá" mean one shelf
 * to a customer and two to a database.
 */
class ProductAttributeTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->context = app(TenantContext::class);
        $this->tenant = Tenant::factory()->create();
        $this->context->set($this->tenant);
    }

    private function writer(): AttributeWriter
    {
        return app(AttributeWriter::class);
    }

    private function attributeWithValues(string $name, array $values): ProductAttribute
    {
        $attribute = $this->writer()->create(['name' => $name]);

        foreach ($values as $value) {
            $this->writer()->addValue($attribute, ['value' => $value]);
        }

        return $attribute->fresh();
    }

    private function product(string $name = 'Obraz'): Product
    {
        return app(ProductWriter::class)->create([
            'name' => $name,
            'price' => 42900,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]);
    }

    public function test_an_attribute_gets_a_code_and_its_values_get_slugs(): void
    {
        $attribute = $this->attributeWithValues('Barva', ['Modrá', 'Černá']);

        $this->assertSame('barva', $attribute->code);
        $this->assertSame(['cerna', 'modra'], $attribute->values->pluck('slug')->sort()->values()->all());
    }

    public function test_two_values_of_the_same_name_still_get_distinct_slugs(): void
    {
        $attribute = $this->attributeWithValues('Barva', ['Modrá', 'Modrá']);

        $this->assertSame(['modra', 'modra-2'], $attribute->values->pluck('slug')->all());
    }

    public function test_renaming_a_value_leaves_its_slug_alone(): void
    {
        // The slug is what a shared link carries. Fixing a typo in the label
        // must not turn every link to that shelf into a dead one.
        $attribute = $this->attributeWithValues('Barva', ['Modá']);
        $value = $attribute->values->first();

        $this->writer()->renameValue($value, 'Modrá');

        $this->assertSame('Modrá', $value->fresh()->value);
        $this->assertSame('moda', $value->fresh()->slug);
    }

    public function test_a_product_carries_the_values_it_was_given(): void
    {
        $attribute = $this->attributeWithValues('Barva', ['Modrá', 'Černá']);
        $product = $this->product();

        $this->writer()->syncForProduct($product, $attribute->values->pluck('id')->all());

        $this->assertCount(2, $product->fresh()->attributeValues);
    }

    public function test_a_value_from_another_shop_is_not_attached(): void
    {
        $mine = $this->attributeWithValues('Barva', ['Modrá']);
        $product = $this->product();

        $other = Tenant::factory()->create();
        $foreignValueId = $this->context->runAs($other, function (): int {
            $attribute = $this->writer()->create(['name' => 'Barva']);

            return $this->writer()->addValue($attribute, ['value' => 'Zelená'])->id;
        });

        $this->writer()->syncForProduct($product, [$mine->values->first()->id, $foreignValueId]);

        $attached = $product->fresh()->attributeValues->pluck('id')->all();

        $this->assertSame([$mine->values->first()->id], $attached);
    }

    public function test_an_attribute_in_use_cannot_be_deleted(): void
    {
        $attribute = $this->attributeWithValues('Barva', ['Modrá']);
        $product = $this->product();
        $this->writer()->syncForProduct($product, [$attribute->values->first()->id]);

        $this->expectException(AttributeInUse::class);

        $this->writer()->delete($attribute);
    }

    public function test_an_unused_attribute_is_deleted_with_its_values(): void
    {
        $attribute = $this->attributeWithValues('Kolekce', ['Jaro']);

        $this->writer()->delete($attribute);

        $this->assertDatabaseMissing('product_attributes', ['id' => $attribute->id]);
        $this->assertSame(0, ProductAttributeValue::query()->count());
    }

    public function test_attributes_never_cross_a_tenant_boundary(): void
    {
        $this->attributeWithValues('Barva', ['Modrá']);

        $other = Tenant::factory()->create();

        $this->assertSame(0, $this->context->runAs($other, fn () => ProductAttribute::query()->count()));
    }
}
