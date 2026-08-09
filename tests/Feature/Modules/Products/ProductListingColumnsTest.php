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
 * The price columns in the product listing (wave 3.10).
 *
 * The listing showed one figure — "Cena s DPH" — which told a shop that is
 * not registered for VAT something untrue, and told nobody what the product
 * cost to buy or what it currently sells for on sale.
 */
class ProductListingColumnsTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private function shop(bool $vatPayer): void
    {
        $this->tenant = Tenant::factory()->create(['vat_payer' => $vatPayer]);
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

    private function product(array $attributes = []): Product
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Kladivo',
            'sku' => 'KLADIVO',
            'price' => 121000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
        ], $attributes)));
    }

    private function url(): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').'/admin/m/products';
    }

    public function test_a_payer_gets_the_net_price_and_the_rate(): void
    {
        $this->shop(vatPayer: true);
        $this->product(['purchase_price' => 60000, 'sale_price' => 99000]);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('vatApplies', true)
                ->where('canSeeCosts', true)
                ->where('products.data.0.price', 121000)
                ->where('products.data.0.net_price', 100000)
                // JSON turns 21.0 into 21; the assertion follows the wire, not PHP.
                ->where('products.data.0.tax_rate', 21)
                ->where('products.data.0.sale_price', 99000)
                ->where('products.data.0.purchase_price', 60000));
    }

    /**
     * Regression guard for wave 3.7: a shop that is not registered is never
     * told anything about tax, and the listing was the last place still
     * claiming "Cena s DPH".
     */
    public function test_a_non_payer_gets_neither(): void
    {
        $this->shop(vatPayer: false);
        $this->product();

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('vatApplies', false)
                ->where('products.data.0.net_price', null)
                ->where('products.data.0.tax_rate', null)
                ->where('products.data.0.price', 121000));
    }

    /**
     * The shelf price, not the effective one: the sale has a column of its
     * own, and two columns showing the same figure would hide what the
     * discount was taken from.
     */
    public function test_a_running_sale_does_not_change_the_final_price_column(): void
    {
        $this->shop(vatPayer: true);
        $this->product(['sale_price' => 99000, 'sale_starts_at' => now()->subDay()]);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.price', 121000)
                ->where('products.data.0.sale_price', 99000));
    }

    public function test_an_amount_that_was_never_filled_in_stays_null(): void
    {
        $this->shop(vatPayer: true);
        $this->product();

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.purchase_price', null)
                ->where('products.data.0.sale_price', null));
    }

    /**
     * Not merely a hidden column: a value the caller may not see never leaves
     * the server, so the listing cannot become a back door to the margin.
     */
    public function test_a_member_without_the_costs_permission_is_not_sent_the_purchase_price(): void
    {
        $this->shop(vatPayer: true);
        $this->product(['purchase_price' => 60000]);

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => ['products.view'],
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canSeeCosts', false)
                ->where('products.data.0.purchase_price', null));
    }

    /**
     * The listing renders a row per product and must not ask the database per
     * row — the rate and the net price are both derived, and deriving them
     * carelessly is how a listing starts costing a query each (regression on
     * the existing N+1 guard).
     */
    public function test_the_listing_does_not_run_a_query_per_row(): void
    {
        $this->shop(vatPayer: true);

        foreach (range(1, 5) as $i) {
            $this->product(['name' => "Kladivo {$i}", 'sku' => "KLADIVO-{$i}"]);
        }

        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($this->owner)->get($this->url())->assertOk();

        $this->assertLessThan(20, $queries);
    }
}
