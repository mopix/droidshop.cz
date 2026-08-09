<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductPriceHistory;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The prices tab: a sale typed as a percentage, and a purchase price with its
 * own VAT rate (wave 3.9).
 */
class PriceTabTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->activateModule($this->tenant, 'products');
    }

    private function url(string $path = ''): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').'/admin/m/products'.$path;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kladivo',
            'price' => '1000',
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
            'status' => Product::STATUS_ACTIVE,
            'weight_g' => 500,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
        ], $overrides);
    }

    private function product(): Product
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => Product::query()->firstOrFail());
    }

    private function create(array $overrides = []): Product
    {
        $this->actingAs($this->owner)->post($this->url(), $this->payload($overrides))->assertRedirect();

        return $this->product();
    }

    public function test_a_percentage_becomes_a_sale_price(): void
    {
        $product = $this->create(['sale_percent' => 20]);

        // 1 000 Kč less 20 % is 800 Kč.
        $this->assertSame(80000, $product->sale_price->amount);
        $this->assertSame(20, $product->sale_percent);
    }

    /**
     * The whole reason the percentage is stored: raising the shelf price has
     * to keep the discount at the percentage that was agreed, instead of
     * quietly turning 20 % off into 12 % off.
     */
    public function test_raising_the_price_keeps_the_discount_at_the_same_percentage(): void
    {
        $product = $this->create(['sale_percent' => 20]);

        $this->actingAs($this->owner)->patch(
            $this->url('/'.$product->slug),
            $this->payload(['price' => '2000', 'sale_percent' => 20]),
        )->assertRedirect();

        $this->assertSame(160000, $this->product()->sale_price->amount);
    }

    /**
     * An amount typed by hand is its own instruction. Recomputing from the
     * percentage on every save would walk the sale price each time somebody
     * opened the form and pressed Save without touching anything.
     */
    public function test_a_typed_amount_wins_over_a_percentage(): void
    {
        $product = $this->create(['sale_price' => '850', 'sale_percent' => 20]);

        $this->assertSame(85000, $product->sale_price->amount);
        $this->assertNull($product->sale_percent);
    }

    public function test_a_percentage_outside_one_to_ninety_nine_is_refused(): void
    {
        $this->actingAs($this->owner)->post($this->url(), $this->payload(['sale_percent' => 100]))
            ->assertSessionHasErrors('sale_percent');

        $this->actingAs($this->owner)->post($this->url(), $this->payload(['sale_percent' => 0]))
            ->assertSessionHasErrors('sale_percent');
    }

    public function test_the_purchase_price_uses_its_own_rate(): void
    {
        $reduced = app(TaxRates::class)->find('reduced'); // 12 %

        $product = $this->create([
            'purchase_net_price' => '500',
            'purchase_tax_rate_id' => $reduced->id,
        ]);

        // 500 Kč at 12 % is 560 Kč — not 605 Kč, which the selling rate of
        // 21 % would have produced.
        $this->assertSame(56000, $product->purchase_price->amount);
        $this->assertSame($reduced->id, $product->purchase_tax_rate_id);
    }

    public function test_an_empty_purchase_rate_inherits_the_products_own(): void
    {
        $product = $this->create(['purchase_net_price' => '500']);

        $this->assertSame(60500, $product->purchase_price->amount);
        $this->assertNull($product->purchase_tax_rate_id);
        $this->assertSame(
            app(TaxRates::class)->find('standard')->id,
            app(TenantContext::class)->runAs($this->tenant, fn () => $product->purchaseRate()->id),
        );
    }

    /**
     * The price history is the legal record of the lowest price in 30 days
     * (wave 2.7, § 12a). A sale price computed from a percentage has to reach
     * it like any other, which it only does by going through ProductWriter.
     */
    public function test_a_computed_sale_price_reaches_the_price_history(): void
    {
        $product = $this->create(['sale_percent' => 20]);

        $recorded = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => ProductPriceHistory::query()->where('product_id', $product->id)->count(),
        );

        $this->assertGreaterThan(0, $recorded);
    }
}
