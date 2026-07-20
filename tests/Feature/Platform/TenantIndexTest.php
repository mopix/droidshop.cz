<?php

namespace Tests\Feature\Platform;

use App\Core\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

class TenantIndexTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
    }

    public function test_listing_requires_a_logged_in_superadmin(): void
    {
        $this->get($this->platformUrl('/superadmin/tenanti'))
            ->assertRedirect(route('platform.login'));
    }

    public function test_listing_is_held_back_until_two_factor_is_passed(): void
    {
        $admin = PlatformAdmin::factory()->withTwoFactor()->create();
        $this->actingAs($admin, 'platform');

        $this->get($this->platformUrl('/superadmin/tenanti'))
            ->assertRedirect(route('platform.2fa.challenge'));
    }

    public function test_listing_does_not_exist_on_a_tenant_host(): void
    {
        $this->actingAsPlatformAdmin();
        Tenant::factory()->withDomain('shop.droidshop')->create();

        $this->get('http://shop.droidshop/superadmin/tenanti')->assertNotFound();
    }

    public function test_a_tenant_user_cannot_reach_the_listing(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($this->platformUrl('/superadmin/tenanti'))
            ->assertRedirect(route('platform.login'));
    }

    public function test_listing_renders_tenants(): void
    {
        $this->actingAsPlatformAdmin();
        $tenant = Tenant::factory()->withDomain('kolo.droidshop')->create(['name' => 'Kolo Shop']);

        $this->get($this->platformUrl('/superadmin/tenanti'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Tenants/Index')
                ->has('tenants.data', 1)
                ->where('tenants.data.0.name', 'Kolo Shop')
                ->where('tenants.data.0.uuid', $tenant->uuid)
                ->where('tenants.data.0.domain', 'kolo.droidshop')
            );
    }

    public function test_listing_is_paginated(): void
    {
        $this->actingAsPlatformAdmin();
        Tenant::factory()->count(30)->create();

        $this->get($this->platformUrl('/superadmin/tenanti'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('tenants.data', 25));
    }

    public function test_listing_can_be_filtered_by_status(): void
    {
        $this->actingAsPlatformAdmin();
        Tenant::factory()->create(['name' => 'Živý', 'status' => TenantStatus::Active]);
        Tenant::factory()->create(['name' => 'Zmrazený', 'status' => TenantStatus::Suspended]);

        $this->get($this->platformUrl('/superadmin/tenanti?status=suspended'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tenants.data', 1)
                ->where('tenants.data.0.name', 'Zmrazený')
            );
    }

    public function test_listing_can_be_filtered_by_plan(): void
    {
        $this->actingAsPlatformAdmin();
        $premium = Plan::factory()->create(['key' => 'premium']);
        Tenant::factory()->create(['plan_id' => $premium->id, 'name' => 'Velký']);
        Tenant::factory()->create(['name' => 'Malý']);

        $this->get($this->platformUrl('/superadmin/tenanti?plan=premium'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tenants.data', 1)
                ->where('tenants.data.0.name', 'Velký')
            );
    }

    public function test_search_matches_name_domain_and_company_id(): void
    {
        $this->actingAsPlatformAdmin();

        // billing_name is pinned on both: the search covers it, and the cs_CZ
        // faker hands out surnames like "Kolář", which contain the needle and
        // made this test fail roughly one run in ten.
        Tenant::factory()->withDomain('bicykl.droidshop')->create([
            'name' => 'Kola s.r.o.', 'billing_name' => 'Kola s.r.o.', 'billing_ico' => '12345678',
        ]);
        Tenant::factory()->withDomain('kniha.droidshop')->create([
            'name' => 'Knihy a.s.', 'billing_name' => 'Knihy a.s.', 'billing_ico' => '87654321',
        ]);

        foreach (['Kola', 'bicykl.droidshop', '12345678'] as $needle) {
            $this->get($this->platformUrl('/superadmin/tenanti?search='.$needle))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('tenants.data', 1)
                    ->where('tenants.data.0.name', 'Kola s.r.o.')
                );
        }
    }

    public function test_unknown_filter_values_are_rejected(): void
    {
        $this->actingAsPlatformAdmin();

        $this->get($this->platformUrl('/superadmin/tenanti?status=nonsense'))
            ->assertSessionHasErrors('status');
    }

    public function test_listing_does_not_run_a_query_per_row(): void
    {
        $this->actingAsPlatformAdmin();
        foreach (range(1, 5) as $i) {
            Tenant::factory()->withDomain("shop{$i}.droidshop")->create();
        }

        DB::enableQueryLog();
        $this->get($this->platformUrl('/superadmin/tenanti'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Tenants, plans, domains, count, session, admin — a handful. Growing
        // with the number of rows would mean the eager loads got dropped.
        $this->assertLessThan(12, $queries, "Expected a fixed number of queries, ran {$queries}.");
    }
}
