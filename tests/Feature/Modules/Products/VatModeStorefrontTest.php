<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * What a shopper is told about VAT (wave 3.7).
 *
 * A shop that is not registered used to print "s DPH · bez DPH 826 Kč" on its
 * product pages — a false statement about somebody else's tax status, on a
 * public page, in a shop whose owner never chose to say it.
 */
class VatModeStorefrontTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private function shop(bool $vatPayer): Tenant
    {
        $this->withoutVite();
        config()->set('cache.default', 'array');

        $tenant = Tenant::factory()->create(['vat_payer' => $vatPayer]);
        Domain::create([
            'tenant_id' => $tenant->id,
            'domain' => 'shop.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);

        $this->artisan('modules:sync')->assertSuccessful();

        foreach (['storefront', 'products', 'categories'] as $module) {
            $this->activateModule($tenant, $module);
        }

        return $tenant;
    }

    private function publish(Tenant $tenant): Product
    {
        return app(TenantContext::class)->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Kladivo',
            'sku' => 'KLADIVO',
            'price' => 121000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
        ]));
    }

    private function url(string $path): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').$path;
    }

    /**
     * The regression guard. Removing VAT for one kind of shop is easy to do
     * in a way that removes it for the other, and a VAT payer is required to
     * show it.
     */
    public function test_a_payer_still_shows_everything_it_did(): void
    {
        $tenant = $this->shop(vatPayer: true);
        $product = $this->publish($tenant);

        $response = $this->get($this->url('/produkt/'.$product->slug))->assertOk();

        $response->assertSee('s DPH');
        $response->assertSee('bez DPH');
        // 1 210 Kč gross at 21 % is 1 000 Kč net. Asserted through Money's own
        // formatter: it uses a non-breaking space, which a literal here would
        // not match.
        $response->assertSee((new Money(100000, 'CZK'))->format(), escape: false);
    }

    public function test_a_non_payer_says_nothing_about_vat_on_the_product_page(): void
    {
        $tenant = $this->shop(vatPayer: false);
        $product = $this->publish($tenant);

        $response = $this->get($this->url('/produkt/'.$product->slug))->assertOk();

        $response->assertDontSee('DPH');
        // The price itself is still there.
        $response->assertSee((new Money(121000, 'CZK'))->format(), escape: false);
    }

    public function test_a_non_payer_says_nothing_about_vat_in_the_listing(): void
    {
        $tenant = $this->shop(vatPayer: false);
        $this->publish($tenant);

        $this->get($this->url('/hledani?q=kladivo'))->assertOk()->assertDontSee('DPH');
    }

    public function test_a_payers_listing_still_says_it(): void
    {
        $tenant = $this->shop(vatPayer: true);
        $this->publish($tenant);

        $this->get($this->url('/hledani?q=kladivo'))->assertOk()->assertSee('s DPH');
    }
}
