<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Checkout\Services\CartPricer;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The VAT recap in the cart and the checkout (wave 3.7).
 *
 * A shop that is not registered for VAT still has a rate on every product —
 * the row is kept so that registering later makes sense — so the recap would
 * otherwise print a tax the customer is not paying.
 */
class VatModeCheckoutTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private function breakdownFor(bool $vatPayer): array
    {
        $tenant = Tenant::factory()->withDomain('shop.droidshop')->create(['vat_payer' => $vatPayer]);

        $this->artisan('modules:sync')->assertSuccessful();

        foreach (['products', 'checkout'] as $module) {
            $this->activateModule($tenant, $module);
        }

        return app(TenantContext::class)->runAs($tenant, function (): array {
            $product = app(ProductWriter::class)->create([
                'name' => 'Kladivo',
                'sku' => 'KLADIVO',
                'price' => 121000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
            ]);

            $cart = Cart::query()->create(['token' => 'tok-vat']);
            $cart->items()->create([
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity' => 1,
                'unit_price' => 121000,
                'currency' => 'CZK',
            ]);

            $pricer = app(CartPricer::class);
            $priced = $pricer->price($cart->fresh());

            return $pricer->vatBreakdown($priced, null, new Money(0, 'CZK'), null, new Money(0, 'CZK'));
        });
    }

    public function test_a_payer_gets_a_recap(): void
    {
        $breakdown = $this->breakdownFor(vatPayer: true);

        $this->assertCount(1, $breakdown);
        $this->assertSame(21.0, $breakdown[0]['rate']);
        $this->assertSame(121000, $breakdown[0]['base'] + $breakdown[0]['vat']);
    }

    public function test_a_non_payer_gets_none(): void
    {
        $this->assertSame([], $this->breakdownFor(vatPayer: false));
    }
}
