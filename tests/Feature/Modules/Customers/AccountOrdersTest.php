<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Orders\Models\Order;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

/**
 * The customer account's order history: a list and a detail, both read
 * through the kernel's OrderBook contract.
 *
 * The detail is the security-critical half (AK 7): findForCustomer() must be
 * scoped to the authenticated customer's own id, not resolved by uuid alone
 * — a foreign order's uuid, guessed or otherwise obtained, must 404 exactly
 * like a foreign cart token or a foreign customer_address id elsewhere in
 * this module.
 */
class AccountOrdersTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'customers', 'orders'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(Tenant $tenant, array $attributes = []): Order
    {
        return $this->context->runAs($tenant, fn () => Order::query()->create(array_merge([
            'number' => '2026'.random_int(100000, 999999),
            'checkout_token' => Str::random(40),
            'email' => 'jana@example.cz',
            'billing' => [
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
            ],
            'currency' => 'CZK',
            'items_total' => 10000,
            'total' => 10000,
            'placed_at' => now(),
        ], $attributes)));
    }

    // --- list ---------------------------------------------------------

    public function test_a_customer_sees_only_their_own_orders_in_the_list(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $other = $this->makeCustomer($this->tenant);

        $this->makeOrder($this->tenant, ['customer_id' => $customer->id, 'number' => 'MOJE-1']);
        $this->makeOrder($this->tenant, ['customer_id' => $other->id, 'number' => 'CIZI-1']);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky'));

        $response->assertOk();
        $response->assertSee('MOJE-1');
        $response->assertDontSee('CIZI-1');
    }

    public function test_the_list_page_is_noindex(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky'));

        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex', false);
    }

    public function test_a_guest_on_the_order_list_is_redirected_to_the_customer_login(): void
    {
        $response = $this->call('GET', $this->url('/ucet/objednavky'));

        $response->assertRedirect($this->url('/prihlaseni'));
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_guest_on_an_order_detail_is_redirected_to_the_customer_login(): void
    {
        $order = $this->makeOrder($this->tenant, ['customer_id' => 1]);

        $response = $this->call('GET', $this->url('/ucet/objednavky/'.$order->uuid));

        $response->assertRedirect($this->url('/prihlaseni'));
        $this->assertFalse(Auth::guard('customer')->check());
    }

    // --- detail: ownership (AK 7) ---------------------------------------

    public function test_a_customer_can_open_their_own_order_detail(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $order = $this->makeOrder($this->tenant, ['customer_id' => $customer->id, 'number' => 'MOJE-42']);

        $this->context->runAs($this->tenant, fn () => $order->items()->create([
            'product_id' => null,
            'name' => 'Klávesnice Acme',
            'sku' => 'KB-1',
            'unit_price' => 10000,
            'tax_rate' => 21.00,
            'quantity' => 1,
            'line_total' => 10000,
            'currency' => 'CZK',
        ]));

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$order->uuid));

        $response->assertOk();
        $response->assertSee('MOJE-42');
        $response->assertSee('Klávesnice Acme');
    }

    public function test_a_foreign_orders_uuid_from_another_customer_at_the_same_shop_is_404(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $owner = $this->makeCustomer($this->tenant);

        $foreign = $this->makeOrder($this->tenant, ['customer_id' => $owner->id, 'number' => 'CIZI-99']);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$foreign->uuid));

        $response->assertNotFound();
        $response->assertDontSee('CIZI-99');
    }

    public function test_a_foreign_orders_uuid_from_another_tenant_is_404(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'customers', 'orders'] as $module) {
            $this->activateModule($other, $module);
        }

        $customer = $this->makeCustomer($this->tenant);
        $otherCustomer = $this->makeCustomer($other);

        $foreign = $this->makeOrder($other, ['customer_id' => $otherCustomer->id, 'number' => 'JINY-TENANT-1']);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$foreign->uuid));

        $response->assertNotFound();
        $response->assertDontSee('JINY-TENANT-1');
    }

    public function test_a_nonexistent_uuid_is_404(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.Str::uuid()));

        $response->assertNotFound();
    }
}
