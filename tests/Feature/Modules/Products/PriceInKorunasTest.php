<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Settings\SettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Prices typed in korunas (wave 3.8).
 *
 * Every admin form asked for haléře until now, so a merchant selling at
 * 1 790 Kč typed `179000` and hoped they had not slipped a digit. The columns
 * are unchanged; only what a person types is.
 */
class PriceInKorunasTest extends TestCase
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

        foreach (['products', 'shipping', 'checkout'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').$path;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kladivo',
            'price' => '1790,50',
            'tax_rate_id' => app(TaxRates::class)->find('standard')->id,
            'status' => Product::STATUS_ACTIVE,
            'weight_g' => 500,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
        ], $overrides);
    }

    private function stored(string $column = 'price'): ?int
    {
        return app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Product::query()->first()?->{$column}?->amount
        );
    }

    public function test_a_price_with_a_decimal_comma_is_stored_in_haleire(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload())
            ->assertRedirect();

        $this->assertSame(179050, $this->stored());
    }

    public function test_a_whole_koruna_amount_works_too(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload(['price' => '1790']))
            ->assertRedirect();

        $this->assertSame(179000, $this->stored());
    }

    public function test_the_sale_and_purchase_prices_are_korunas_as_well(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload([
            'sale_price' => '1499,90',
            'purchase_price' => '900',
        ]))->assertRedirect();

        $this->assertSame(149990, $this->stored('sale_price'));
        $this->assertSame(90000, $this->stored('purchase_price'));
    }

    /**
     * `lt:price` compares the sale price against the shelf price. Converting
     * after validation would have it comparing korunas against haléře, and
     * every sale price would look valid.
     */
    public function test_a_sale_price_above_the_shelf_price_is_still_refused(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload([
            'price' => '1000',
            'sale_price' => '1500',
        ]))->assertSessionHasErrors('sale_price');
    }

    /**
     * What the merchant actually experiences: save, reopen, and the figure is
     * the one they typed — not 179050, and not 1790.
     */
    public function test_the_form_gives_back_what_was_typed(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload());

        $slug = app(TenantContext::class)->runAs($this->tenant, fn () => Product::query()->value('slug'));

        $this->actingAs($this->owner)
            ->get($this->url('/admin/m/products/'.$slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.price', '1790,50'));
    }

    /**
     * A blank purchase price means "not filled in", not "free".
     */
    public function test_an_empty_amount_stays_empty(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload([
            'purchase_price' => '',
        ]))->assertRedirect();

        $this->assertNull($this->stored('purchase_price'));
    }

    public function test_a_malformed_amount_is_a_validation_error_not_a_crash(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload([
            'price' => '1 79O,00', // letter O
        ]))->assertSessionHasErrors('price');
    }

    /**
     * The VAT-free field from wave 3.7 takes korunas too, and the gross field
     * still wins when both arrive.
     */
    public function test_the_net_price_field_takes_korunas(): void
    {
        $this->actingAs($this->owner)->post($this->url('/admin/m/products'), $this->payload([
            'price' => null,
            'net_price' => '1000',
        ]))->assertRedirect();

        $this->assertSame(121000, $this->stored());
    }

    public function test_a_module_setting_marked_as_money_takes_korunas(): void
    {
        $this->actingAs($this->owner)->patch(
            $this->url('/admin/nastaveni/moduly/checkout'),
            ['values' => ['min_order_total' => '1000,50', 'guest_checkout' => true]],
        )->assertRedirect();

        $this->assertSame(100050, (int) app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(SettingsService::class)->get('checkout', 'min_order_total')
        ));
    }
}
