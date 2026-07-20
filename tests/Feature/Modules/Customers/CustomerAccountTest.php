<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Customers\Models\CustomerAddress;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();
        app(TenantContext::class)->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function addressPayload(array $overrides = []): array
    {
        return array_merge([
            'kind' => CustomerAddress::KIND_SHIPPING,
            'street' => 'Ulice 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country' => 'CZ',
        ], $overrides);
    }

    public function test_a_guest_is_redirected_to_the_customer_login_from_every_account_url(): void
    {
        $routes = [
            ['GET', '/ucet'],
            ['GET', '/ucet/udaje'],
            ['PUT', '/ucet/udaje'],
            ['GET', '/ucet/adresy'],
            ['POST', '/ucet/adresy'],
            ['GET', '/ucet/adresy/1/upravit'],
            ['PUT', '/ucet/adresy/1'],
            ['GET', '/ucet/adresy/1/smazat'],
            ['DELETE', '/ucet/adresy/1'],
        ];

        foreach ($routes as [$method, $path]) {
            // Every account URL must land on this shop's own customer login,
            // never the tenant staff Breeze login — the redirect guard is
            // per-guard (bootstrap/app.php), not per path.
            $this->call($method, $this->url($path))
                ->assertRedirect($this->url('/prihlaseni'));
        }

        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_the_overview_page_is_noindex_and_renders_server_side(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet'));

        $response->assertOk();
        $response->assertSee('noindex', false);
    }

    public function test_the_overview_renders_for_a_customer_with_no_addresses_and_no_orders(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet'));

        $response->assertOk();
    }

    public function test_the_overview_shows_only_the_signed_in_customers_own_name(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['first_name' => 'Jana', 'last_name' => 'Nováková']);
        $this->makeCustomer($this->tenant, ['first_name' => 'Petr', 'last_name' => 'Svoboda']);

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet'));

        $response->assertOk();
        $response->assertSee('Jana Nováková');
        $response->assertDontSee('Petr Svoboda');
    }

    public function test_a_customer_of_shop_a_cannot_reach_shop_bs_account_pages_even_with_a_valid_session(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        $customerA = $this->makeCustomer($this->tenant, ['email' => 'jan@example.test']);

        // Simulates a session that already resolved to shop A's customer
        // being presented to shop B's host (e.g. a stray cookie). The
        // guard's in-memory user is never set directly (no actingAs/login
        // call precedes this), so this exercises the real lookup path: the
        // Eloquent provider's own tenant scope, not a cached guard user.
        $sessionKey = Auth::guard('customer')->getName();

        $response = $this->withSession([$sessionKey => $customerA->id])
            ->get('http://shop2.droidshop/ucet');

        $response->assertRedirect('http://shop2.droidshop/prihlaseni');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_updating_the_profile_saves_name_and_phone(): void
    {
        // Seeded with different names than are submitted below: a write that
        // silently dropped first_name/last_name (e.g. only persisting phone)
        // would still pass an assertion that merely re-submits the seeded
        // values, so this asserts all three fields actually changed.
        $customer = $this->makeCustomer($this->tenant, ['first_name' => 'Petra', 'last_name' => 'Malá', 'phone' => null]);

        $response = $this->actingAsCustomer($customer)->put($this->url('/ucet/udaje'), [
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'phone' => '+420111222333',
        ]);

        $response->assertRedirect($this->url('/ucet/udaje'));

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertSame('Jan', $fresh->first_name);
        $this->assertSame('Novák', $fresh->last_name);
        $this->assertSame('+420111222333', $fresh->phone);
    }

    public function test_updating_the_profile_validates_required_fields(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->put($this->url('/ucet/udaje'), [
            'first_name' => '',
            'last_name' => '',
        ]);

        $response->assertSessionHasErrors(['first_name', 'last_name']);
    }

    public function test_changing_the_password_requires_the_correct_current_password(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['password' => Hash::make('puvodniheslo')]);

        $response = $this->actingAsCustomer($customer)->put($this->url('/ucet/udaje'), [
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'current_password' => 'spatneheslo',
            'password' => 'noveheslo123',
            'password_confirmation' => 'noveheslo123',
        ]);

        $response->assertSessionHasErrors('current_password');

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertTrue(Hash::check('puvodniheslo', $fresh->password));
    }

    public function test_changing_the_password_with_the_correct_current_password_succeeds(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['password' => Hash::make('puvodniheslo')]);

        $response = $this->actingAsCustomer($customer)->put($this->url('/ucet/udaje'), [
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'current_password' => 'puvodniheslo',
            'password' => 'noveheslo123',
            'password_confirmation' => 'noveheslo123',
        ]);

        $response->assertRedirect($this->url('/ucet/udaje'));

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $customer->fresh());
        $this->assertTrue(Hash::check('noveheslo123', $fresh->password));
    }

    public function test_the_addresses_page_lists_only_the_customers_own_addresses(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $other = $this->makeCustomer($this->tenant);

        app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $customer->addresses()->create($this->addressPayload(['city' => 'Moje Město']))
        );
        app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $other->addresses()->create($this->addressPayload(['city' => 'Cizí Město']))
        );

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/adresy'));

        $response->assertOk();
        $response->assertSee('Moje Město');
        $response->assertDontSee('Cizí Město');
    }

    public function test_the_add_address_form_is_not_prefilled_from_an_existing_address(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $customer->addresses()->create($this->addressPayload(['street' => 'Existující ulice 5', 'is_default' => true]))
        );

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/adresy'));

        $response->assertOk();
        // @foreach leaves $address bound to the last listed address in this
        // view's scope, and a plain @include inherits it — this catches a
        // regression back to that: the "add address" form's own street
        // field must render empty, not carry over the address above it.
        $response->assertSee('id="street" name="street" type="text" value="" required', false);
        $response->assertDontSee('value="Existující ulice 5"', false);
    }

    public function test_adding_an_address_belongs_to_the_authenticated_customer(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)
            ->post($this->url('/ucet/adresy'), $this->addressPayload(['street' => 'Nová 5']));

        $response->assertRedirect($this->url('/ucet/adresy'));

        $address = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => CustomerAddress::where('street', 'Nová 5')->first()
        );

        $this->assertNotNull($address);
        $this->assertSame($customer->id, $address->customer_id);
        $this->assertSame($this->tenant->id, $address->tenant_id);
    }

    public function test_marking_a_new_address_default_unsets_the_previous_default_of_the_same_kind(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $first = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $customer->addresses()->create($this->addressPayload(['street' => 'První', 'is_default' => true]))
        );

        // A second customer's own default of the same kind. An unscoped
        // CustomerAddress::where('kind', $kind)->update(...) would clear
        // this too — this address belongs to nobody involved in the request
        // below, so it must survive untouched.
        $other = $this->makeCustomer($this->tenant);
        $othersDefault = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $other->addresses()->create($this->addressPayload(['street' => 'Cizí výchozí', 'is_default' => true]))
        );

        $this->actingAsCustomer($customer)
            ->post($this->url('/ucet/adresy'), $this->addressPayload(['street' => 'Druhá', 'is_default' => '1']))
            ->assertRedirect($this->url('/ucet/adresy'));

        $freshFirst = app(TenantContext::class)->runAs($this->tenant, fn () => $first->fresh());
        $second = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => CustomerAddress::where('street', 'Druhá')->first()
        );
        $freshOthersDefault = app(TenantContext::class)->runAs($this->tenant, fn () => $othersDefault->fresh());

        $this->assertFalse($freshFirst->is_default);
        $this->assertTrue($second->is_default);
        $this->assertTrue($freshOthersDefault->is_default);
    }

    public function test_a_customer_can_edit_and_delete_their_own_address(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $address = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $customer->addresses()->create($this->addressPayload(['street' => 'Stará']))
        );

        $this->actingAsCustomer($customer)
            ->put($this->url('/ucet/adresy/'.$address->id), $this->addressPayload(['street' => 'Nová']))
            ->assertRedirect($this->url('/ucet/adresy'));

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $address->fresh());
        $this->assertSame('Nová', $fresh->street);

        $this->actingAsCustomer($customer)
            ->delete($this->url('/ucet/adresy/'.$address->id))
            ->assertRedirect($this->url('/ucet/adresy'));

        $stillExists = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => CustomerAddress::whereKey($address->id)->exists()
        );
        $this->assertFalse($stillExists);
    }

    public function test_the_delete_confirmation_page_renders_server_side_without_deleting(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $address = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $customer->addresses()->create($this->addressPayload())
        );

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/adresy/'.$address->id.'/smazat'));

        $response->assertOk();
        // Not merely "a <form exists somewhere" — the shop layout renders a
        // search form in the header on every page, which would make that
        // assertion pass even against an emptied address-delete.blade.php.
        // What actually matters: the destroy action targets this specific
        // address, and the DELETE method spoof is present.
        $response->assertSee('action="'.$this->url('/ucet/adresy/'.$address->id).'"', false);
        $response->assertSee('name="_method" value="DELETE"', false);

        $stillExists = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => CustomerAddress::whereKey($address->id)->exists()
        );
        $this->assertTrue($stillExists);
    }

    public function test_editing_an_address_the_customer_does_not_own_returns_404_and_leaves_it_unchanged(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $owner = $this->makeCustomer($this->tenant);

        $address = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $owner->addresses()->create($this->addressPayload(['street' => 'Původní']))
        );

        $response = $this->actingAsCustomer($customer)
            ->put($this->url('/ucet/adresy/'.$address->id), $this->addressPayload(['street' => 'Přepsaná']));

        $response->assertNotFound();

        $fresh = app(TenantContext::class)->runAs($this->tenant, fn () => $address->fresh());
        $this->assertSame('Původní', $fresh->street);
    }

    public function test_deleting_an_address_the_customer_does_not_own_returns_404_and_leaves_it_in_place(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $owner = $this->makeCustomer($this->tenant);

        $address = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $owner->addresses()->create($this->addressPayload())
        );

        $response = $this->actingAsCustomer($customer)
            ->delete($this->url('/ucet/adresy/'.$address->id));

        $response->assertNotFound();

        $stillExists = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => CustomerAddress::whereKey($address->id)->exists()
        );
        $this->assertTrue($stillExists);
    }

    public function test_viewing_the_edit_form_of_an_address_the_customer_does_not_own_returns_404(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $owner = $this->makeCustomer($this->tenant);

        $address = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $owner->addresses()->create($this->addressPayload(['street' => 'Cizí ulice pro úpravu', 'company' => 'Cizí Firma s.r.o.']))
        );

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/adresy/'.$address->id.'/upravit'));

        $response->assertNotFound();
        // The GET routes render someone else's street, company, reg_no and
        // vat_no into a form — only the PUT/DELETE were previously tested
        // against a foreign address id, leaving this read leak untested.
        $response->assertDontSee('Cizí ulice pro úpravu');
        $response->assertDontSee('Cizí Firma s.r.o.');
    }

    public function test_viewing_the_delete_confirmation_of_an_address_the_customer_does_not_own_returns_404(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $owner = $this->makeCustomer($this->tenant);

        $address = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => $owner->addresses()->create($this->addressPayload(['street' => 'Cizí ulice pro smazání']))
        );

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/adresy/'.$address->id.'/smazat'));

        $response->assertNotFound();
        $response->assertDontSee('Cizí ulice pro smazání');
    }

    public function test_an_address_of_a_customer_at_another_shop_is_404_and_untouched_from_every_route(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'customers'] as $module) {
            $this->activateModule($other, $module);
        }

        $customerA = $this->makeCustomer($this->tenant);
        $customerB = $this->makeCustomer($other);

        $addressB = app(TenantContext::class)->runAs(
            $other,
            fn () => $customerB->addresses()->create($this->addressPayload(['street' => 'Adresa obchodu B']))
        );

        // customerA, signed in on shop A's own host, tries every address
        // route against a row that belongs to shop B. There is no composite
        // foreign key tying customer_addresses.tenant_id to its customer's
        // tenant, so this rests entirely on $customer->addresses() scoping
        // the lookup query correctly rather than on schema-level protection.
        $this->actingAsCustomer($customerA)
            ->get($this->url('/ucet/adresy/'.$addressB->id.'/upravit'))
            ->assertNotFound();

        $this->actingAsCustomer($customerA)
            ->get($this->url('/ucet/adresy/'.$addressB->id.'/smazat'))
            ->assertNotFound();

        $this->actingAsCustomer($customerA)
            ->put($this->url('/ucet/adresy/'.$addressB->id), $this->addressPayload(['street' => 'Přepsáno']))
            ->assertNotFound();

        $this->actingAsCustomer($customerA)
            ->delete($this->url('/ucet/adresy/'.$addressB->id))
            ->assertNotFound();

        $freshB = app(TenantContext::class)->runAs($other, fn () => $addressB->fresh());
        $this->assertNotNull($freshB);
        $this->assertSame('Adresa obchodu B', $freshB->street);
    }
}
