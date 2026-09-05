<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\ProductQuery;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Services\AttributeWriter;
use Modules\Products\Services\EloquentProductCatalog;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

/**
 * Filtering the catalogue by descriptive properties (wave 4.2, task B2).
 *
 * The rule the whole feature turns on: OR inside one attribute, AND between
 * them. That is what a shopper means by "blue or black, for a bedroom", and
 * getting it backwards shows the goods that are somehow both colours at once.
 */
class ProductFacetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ProductAttribute $colour;

    private ProductAttribute $room;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->colour = $this->attribute('Barva', ['Modrá', 'Černá', 'Zelená']);
        $this->room = $this->attribute('Umístění', ['Do ložnice', 'Do kuchyně']);
    }

    private function attribute(string $name, array $values): ProductAttribute
    {
        $attribute = app(AttributeWriter::class)->create(['name' => $name]);

        foreach ($values as $value) {
            app(AttributeWriter::class)->addValue($attribute, ['value' => $value]);
        }

        return $attribute->fresh();
    }

    /**
     * @param  list<string>  $valueSlugs
     */
    private function product(string $name, array $valueSlugs): Product
    {
        $product = app(ProductWriter::class)->create([
            'name' => $name,
            'price' => 42900,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]);

        $ids = collect([$this->colour, $this->room])
            ->flatMap(fn (ProductAttribute $a) => $a->values)
            ->whereIn('slug', $valueSlugs)
            ->pluck('id')
            ->all();

        app(AttributeWriter::class)->syncForProduct($product, $ids);

        return $product;
    }

    /**
     * @param  array<string, list<string>>  $attributes
     * @return list<string>
     */
    private function namesFor(array $attributes): array
    {
        // Sorted by name with Czech collation, so the assertions read the way
        // a person would list them rather than the way strcmp does.
        $names = collect(app(ProductCatalog::class)->paginate(new ProductQuery(attributes: $attributes))->items())
            ->map(fn ($product) => $product->catalogName())
            ->all();

        usort($names, static fn (string $a, string $b): int => strcoll($a, $b) ?: strcmp($a, $b));

        return $names;
    }

    public function test_two_values_of_one_attribute_are_a_union(): void
    {
        $this->product('Modrý obraz', ['modra']);
        $this->product('Černý obraz', ['cerna']);
        $this->product('Zelený obraz', ['zelena']);

        $this->assertSame(
            ['Modrý obraz', 'Černý obraz'],
            $this->namesFor(['barva' => ['modra', 'cerna']]),
        );
    }

    public function test_two_attributes_are_an_intersection(): void
    {
        $this->product('Modrý do ložnice', ['modra', 'do-loznice']);
        $this->product('Modrý do kuchyně', ['modra', 'do-kuchyne']);
        $this->product('Černý do ložnice', ['cerna', 'do-loznice']);

        $this->assertSame(
            ['Modrý do ložnice'],
            $this->namesFor(['barva' => ['modra'], 'umisteni' => ['do-loznice']]),
        );
    }

    public function test_an_unknown_attribute_or_value_does_not_empty_the_listing(): void
    {
        // A stale link or a crawler guessing must land on the shelf, not on
        // "we sell nothing".
        $this->product('Modrý obraz', ['modra']);

        $this->assertSame(['Modrý obraz'], $this->namesFor(['neexistuje' => ['nic']]));
        $this->assertSame(['Modrý obraz'], $this->namesFor(['barva' => ['ruzova']]));
    }

    public function test_a_filter_never_reaches_another_shops_goods(): void
    {
        $this->product('Modrý obraz', ['modra']);

        $other = Tenant::factory()->create();

        $this->assertSame([], app(TenantContext::class)->runAs(
            $other,
            fn () => $this->namesFor(['barva' => ['modra']]),
        ));
    }

    public function test_the_query_object_normalises_what_arrives_from_the_url(): void
    {
        // Sorted and de-duplicated, because this same value goes into the
        // page-cache key: two orderings of one filter must be one entry.
        $query = ProductQuery::fromInput(['vlastnost' => ['barva' => 'cerna,modra,modra']]);

        $this->assertSame(['barva' => ['cerna', 'modra']], $query->attributes);

        $reversed = ProductQuery::fromInput(['vlastnost' => ['barva' => 'modra,cerna']]);

        $this->assertSame($query->attributes, $reversed->attributes);
    }

    public function test_the_query_object_drops_anything_that_is_not_a_slug(): void
    {
        $query = ProductQuery::fromInput(['vlastnost' => [
            'barva' => 'modrá; drop table',
            'NEPLATNY KOD' => 'modra',
        ]]);

        $this->assertSame([], $query->attributes);
    }

    public function test_a_page_size_the_shop_does_not_offer_is_ignored(): void
    {
        $this->assertSame(48, ProductQuery::fromInput(['na-stranku' => '48'])->perPage);
        $this->assertSame(24, ProductQuery::fromInput(['na-stranku' => '100000'])->perPage);
        $this->assertSame(24, ProductQuery::fromInput(['na-stranku' => 'hodne'])->perPage);
    }

    public function test_counts_are_taken_without_the_attribute_they_describe(): void
    {
        // With the attribute included, every unselected value reads zero and
        // the panel becomes a list of dead ends.
        $this->product('Modrý do ložnice', ['modra', 'do-loznice']);
        $this->product('Černý do ložnice', ['cerna', 'do-loznice']);
        $this->product('Modrý do kuchyně', ['modra', 'do-kuchyne']);

        $counts = app(EloquentProductCatalog::class)
            ->facetCounts(new ProductQuery(attributes: ['barva' => ['modra']]));

        $this->assertSame(2, $counts['barva']['modra']);
        $this->assertSame(1, $counts['barva']['cerna']);
        // Rooms are counted with the colour filter still applied.
        $this->assertSame(1, $counts['umisteni']['do-loznice']);
        $this->assertSame(1, $counts['umisteni']['do-kuchyne']);
    }
}
