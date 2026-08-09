<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Setting a campaign from the admin — and the guardrails that keep a "sale"
 * from being an increase or a window that ends before it opens.
 */
class SaleAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'products');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/products'.$path;
    }

    private function rateId(): int
    {
        return app(TaxRates::class)->default()->id;
    }

    private function make(): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Notebook Acme 14',
            'price' => 100_000,
            'tax_rate_id' => $this->rateId(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Notebook Acme 14',
            // The form takes korunas since wave 3.8; ProductWriter (used by
            // make() above) still takes haléře, because it is not a form.
            'price' => '1000',
            'tax_rate_id' => $this->rateId(),
            'status' => Product::STATUS_DRAFT,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
            'weight_g' => 1800,
            ...$overrides,
        ];
    }

    public function test_an_owner_sets_a_sale_price_with_a_window(): void
    {
        $product = $this->make();

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug), $this->payload([
                'sale_price' => '799',
                'sale_starts_at' => '2026-08-01 00:00:00',
                'sale_ends_at' => '2026-08-08 00:00:00',
            ]))
            ->assertRedirect();

        $fresh = $this->context->runAs($this->tenant, fn () => $product->fresh());

        $this->assertSame(79_900, $fresh->sale_price->amount);
        $this->assertSame('2026-08-01 00:00:00', $fresh->sale_starts_at->toDateTimeString());
        $this->assertSame('2026-08-08 00:00:00', $fresh->sale_ends_at->toDateTimeString());
    }

    public function test_a_sale_price_above_the_regular_price_is_rejected(): void
    {
        $product = $this->make();

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug), $this->payload(['sale_price' => '1500']))
            ->assertSessionHasErrors('sale_price');
    }

    public function test_a_window_that_ends_before_it_starts_is_rejected(): void
    {
        $product = $this->make();

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug), $this->payload([
                'sale_price' => '799',
                'sale_starts_at' => '2026-08-08 00:00:00',
                'sale_ends_at' => '2026-08-01 00:00:00',
            ]))
            ->assertSessionHasErrors('sale_ends_at');
    }

    public function test_clearing_the_sale_price_ends_the_campaign(): void
    {
        $product = $this->make();

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug), $this->payload(['sale_price' => 79_900]));

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug), $this->payload(['sale_price' => null]));

        $fresh = $this->context->runAs($this->tenant, fn () => $product->fresh());

        $this->assertNull($fresh->sale_price);
        $this->assertFalse($fresh->catalogIsOnSale());
    }
}
