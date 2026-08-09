<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The product form for a VAT payer and for everyone else (wave 3.7).
 *
 * Two failure modes matter here and they pull in opposite directions: a shop
 * that is not registered being made to answer questions about tax it cannot
 * charge, and the tidy-up quietly taking those questions away from a shop
 * that is registered. Both are tested.
 */
class VatModeAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private function shop(bool $vatPayer): array
    {
        $tenant = Tenant::factory()->create(['vat_payer' => $vatPayer]);
        Domain::create([
            'tenant_id' => $tenant->id,
            'domain' => 'shop.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->artisan('modules:sync')->assertSuccessful();
        $this->activateModule($tenant, 'products');

        return [$tenant, $owner];
    }

    private function url(string $path = ''): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').'/admin/m/products'.$path;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kladivo',
            'status' => Product::STATUS_ACTIVE,
            // Korunas since wave 3.8; 1 210 Kč is 121 000 haléřů.
            'price' => '1210',
            'weight_g' => 500,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
        ], $overrides);
    }

    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return app(TenantContext::class)->runAs($tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Kladivo',
            'sku' => 'KLADIVO',
            'price' => 121000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ], $attributes)));
    }

    // --- VAT payer ------------------------------------------------------

    /**
     * The point of the feature: wholesale price lists quote net, and retyping
     * them through a calculator is how a haléř gets lost.
     */
    public function test_a_payer_can_enter_the_price_without_vat(): void
    {
        [$tenant, $owner] = $this->shop(vatPayer: true);
        $standard = app(TaxRates::class)->find('standard');

        $this->actingAs($owner)->post($this->url(), $this->payload([
            'price' => null,
            'net_price' => '1000',
            'tax_rate_id' => $standard->id,
        ]))->assertRedirect();

        // 1 000 Kč net at 21 % is 1 210 Kč gross.
        $this->assertSame(121000, app(TenantContext::class)->runAs(
            $tenant, fn () => Product::query()->first()->price->amount
        ));
    }

    public function test_a_payer_can_still_enter_the_price_with_vat(): void
    {
        [$tenant, $owner] = $this->shop(vatPayer: true);

        $this->actingAs($owner)->post($this->url(), $this->payload([
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
        ]))->assertRedirect();

        $this->assertSame(121000, app(TenantContext::class)->runAs(
            $tenant, fn () => Product::query()->first()->price->amount
        ));
    }

    /**
     * Recomputing from net on every save would walk the price by a haléř each
     * time somebody opened the form and pressed Save without touching it.
     */
    public function test_when_both_prices_arrive_the_gross_one_wins(): void
    {
        [$tenant, $owner] = $this->shop(vatPayer: true);

        $this->actingAs($owner)->post($this->url(), $this->payload([
            'price' => '999',
            'net_price' => '1000',
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
        ]))->assertRedirect();

        $this->assertSame(99900, app(TenantContext::class)->runAs(
            $tenant, fn () => Product::query()->first()->price->amount
        ));
    }

    public function test_a_payer_still_has_to_pick_a_rate(): void
    {
        [, $owner] = $this->shop(vatPayer: true);

        $this->actingAs($owner)->post($this->url(), $this->payload())
            ->assertSessionHasErrors('tax_rate_id');
    }

    /**
     * Regression guard: the tidy-up for non-payers must not take anything
     * away from a shop that is registered.
     */
    public function test_a_payer_sees_the_rates_and_the_net_price(): void
    {
        [$tenant, $owner] = $this->shop(vatPayer: true);
        $product = $this->makeProduct($tenant);

        $this->actingAs($owner)
            ->get($this->url('/'.$product->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('vatApplies', true)
                ->where('product.net_price', '1000,00')
                ->where('product.sale_percent', null)
                ->has('taxRates', 3));
    }

    // --- Not registered for VAT ----------------------------------------

    public function test_a_non_payer_is_not_asked_for_a_rate(): void
    {
        [$tenant, $owner] = $this->shop(vatPayer: false);

        $this->actingAs($owner)->post($this->url(), $this->payload())->assertRedirect();

        $this->assertSame(121000, app(TenantContext::class)->runAs(
            $tenant, fn () => Product::query()->first()->price->amount
        ));
    }

    public function test_a_non_payer_gets_no_rates_and_no_net_price(): void
    {
        [$tenant, $owner] = $this->shop(vatPayer: false);
        $product = $this->makeProduct($tenant);

        $this->actingAs($owner)
            ->get($this->url('/'.$product->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('vatApplies', false)
                ->where('product.net_price', null)
                ->where('taxRates', []));
    }

    /**
     * A variant never carries its own VAT rate (wave 2.4), so the conversion
     * uses the product's — read off the bound product, not off the request,
     * where a client could name a different one.
     */
    public function test_a_variant_price_can_be_entered_without_vat(): void
    {
        [$tenant, $owner] = $this->shop(vatPayer: true);
        $product = $this->makeProduct($tenant);

        $variantId = app(TenantContext::class)->runAs($tenant, function () use ($product) {
            $option = ProductOption::create([
                'product_id' => $product->id, 'name' => 'Velikost', 'position' => 0,
            ]);
            $value = $option->values()->create(['value' => 'M', 'position' => 0]);

            $variant = ProductVariant::create([
                'product_id' => $product->id, 'position' => 0, 'price' => 100000,
                'stock_tracked' => false, 'stock_qty' => 0,
            ]);
            $variant->optionValues()->attach($value->id);

            return $variant->id;
        });

        $this->actingAs($owner)->patch(
            $this->url('/'.$product->slug.'/varianty/'.$variantId),
            ['net_price' => '2000', 'stock_qty' => 0],
        )->assertRedirect();

        // 2 000 Kč net at 21 % is 2 420 Kč gross.
        $this->assertSame(242000, app(TenantContext::class)->runAs(
            $tenant, fn () => ProductVariant::find($variantId)->price->amount
        ));
    }

    /**
     * The owner's decision: a rate already on the row stays there, so that
     * registering for VAT later leaves the catalogue making sense straight
     * away rather than needing every product revisited.
     */
    public function test_saving_as_a_non_payer_leaves_an_existing_rate_alone(): void
    {
        [$tenant, $owner] = $this->shop(vatPayer: false);
        $reduced = app(TaxRates::class)->find('reduced');
        $product = $this->makeProduct($tenant, ['tax_rate_id' => $reduced->id]);

        $this->actingAs($owner)->patch($this->url('/'.$product->slug), $this->payload([
            'name' => 'Kladivo velké',
            'price' => '1500',
        ]))->assertRedirect();

        $this->assertSame($reduced->id, $product->fresh()->tax_rate_id);
    }
}
