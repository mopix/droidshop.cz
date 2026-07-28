<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductImporter;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class ProductImporterTest extends TestCase
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
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function import(array $row, bool $dryRun = false): array
    {
        return $this->context->runAs(
            $this->tenant,
            fn () => app(ProductImporter::class)->import($row, $dryRun),
        );
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [
            'typ' => 'produkt',
            'sku' => 'ACME-1',
            'nazev' => 'Klávesnice Acme',
            'cena' => '1290,00',
            'dph' => '21',
            'stav' => 'aktivni',
            ...$overrides,
        ];
    }

    public function test_it_creates_a_product(): void
    {
        $this->assertSame([], $this->import($this->row()));

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());

        $this->assertSame('Klávesnice Acme', $product->name);
        $this->assertSame(129000, $product->price->amount);
        $this->assertSame(Product::STATUS_ACTIVE, $product->status);
    }

    public function test_the_same_sku_updates_instead_of_duplicating(): void
    {
        $this->import($this->row());
        $this->import($this->row(['cena' => '999,00']));

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(1, Product::query()->count());
            $this->assertSame(99900, Product::query()->firstOrFail()->price->amount);
        });
    }

    public function test_an_empty_cell_on_update_keeps_the_previous_value(): void
    {
        $this->import($this->row(['kratky_popis' => 'Původní popis']));
        $this->import($this->row(['kratky_popis' => '']));

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());

        $this->assertSame('Původní popis', $product->short_description);
    }

    public function test_html_in_the_description_is_sanitised(): void
    {
        $this->import($this->row(['popis' => '<p>Dobrá<script>alert(1)</script></p>']));

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());

        $this->assertStringNotContainsString('<script', (string) $product->description);
    }

    public function test_the_price_lands_in_the_history(): void
    {
        $this->import($this->row());

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());

        $this->assertDatabaseHas('product_price_history', [
            'product_id' => $product->id,
            'price' => 129000,
        ]);
    }

    public function test_an_existing_category_path_is_attached(): void
    {
        $this->context->runAs($this->tenant, function () {
            $parent = Category::query()->create(['name' => 'Elektronika', 'slug' => 'elektronika']);
            Category::query()->create(['name' => 'Klávesnice', 'slug' => 'klavesnice', 'parent_id' => $parent->id]);
        });

        $this->assertSame([], $this->import($this->row(['kategorie' => 'Elektronika > Klávesnice'])));

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->with('categories')->firstOrFail());

        $this->assertSame(['Klávesnice'], $product->categories->pluck('name')->all());
    }

    public function test_an_unknown_category_path_fails_the_row_and_writes_nothing(): void
    {
        $errors = $this->import($this->row(['kategorie' => 'Neexistující > Větev']));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Kategorie', implode(' ', $errors));
        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->assertSame([], $this->import($this->row(), dryRun: true));

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_a_variant_row_lands_under_its_parent(): void
    {
        $this->import($this->row());

        $errors = $this->import([
            'typ' => 'varianta',
            'sku' => 'ACME-1-M',
            'varianta_rodic_sku' => 'ACME-1',
            'varianta_hodnoty' => 'Velikost:M',
            'cena' => '1390,00',
            'sklad_ks' => '5',
        ]);

        $this->assertSame([], $errors);

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->with('variants')->firstOrFail());

        $this->assertCount(1, $product->variants);
        $this->assertSame(139000, $product->variants->first()->price->amount);
    }

    public function test_a_variant_without_a_known_parent_fails(): void
    {
        $errors = $this->import([
            'typ' => 'varianta',
            'sku' => 'X-M',
            'varianta_rodic_sku' => 'NEEXISTUJE',
            'varianta_hodnoty' => 'Velikost:M',
        ]);

        $this->assertStringContainsString('Rodičovský produkt', implode(' ', $errors));
    }

    public function test_a_duplicate_sku_in_the_catalogue_fails_the_row(): void
    {
        $this->context->runAs($this->tenant, function () {
            foreach (['A', 'B'] as $name) {
                app(ProductWriter::class)->create([
                    'name' => 'Produkt '.$name,
                    'sku' => 'DUP-1',
                    'price' => 10000,
                    'tax_rate_id' => app(TaxRates::class)->default()->id,
                ]);
            }
        });

        $errors = $this->import($this->row(['sku' => 'DUP-1']));

        $this->assertStringContainsString('Více produktů', implode(' ', $errors));
    }
}
