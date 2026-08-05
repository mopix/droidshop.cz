<?php

namespace Tests\Feature\Tenancy;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spatie/laravel-multitenancy's makeCurrent() short-circuits when a tenant
 * with the same primary key is already bound, which leaves the FIRST
 * instance bound in the container for the rest of the worker's life. Wave
 * 3.0 worked around it in TenantContext::set(), but runAs() and
 * runWithoutTenant() called makeCurrent() directly and kept the hole: every
 * caller that switches through them (superadmin status change, Stripe
 * webhook, lifecycle sweeper, TenantProvisioner, AuditLog) could read
 * attributes from a stale model.
 */
class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->context->forget();

        parent::tearDown();
    }

    public function test_run_as_binds_the_instance_it_was_given(): void
    {
        $tenant = Tenant::factory()->create(['page_gen_catalog' => 1]);
        $this->context->set($tenant);

        Tenant::query()->whereKey($tenant->id)->update(['page_gen_catalog' => 2]);
        $fresh = Tenant::query()->find($tenant->id);

        $seen = $this->context->runAs($fresh, fn () => $this->context->current()->page_gen_catalog);

        $this->assertSame(2, $seen);
    }

    public function test_run_as_restores_the_previous_instance(): void
    {
        $tenant = Tenant::factory()->create(['page_gen_catalog' => 1]);
        $this->context->set($tenant);

        Tenant::query()->whereKey($tenant->id)->update(['page_gen_catalog' => 2]);
        $fresh = Tenant::query()->find($tenant->id);

        $this->context->runAs($fresh, fn () => null);

        $this->assertSame(1, $this->context->current()->page_gen_catalog);
    }

    public function test_run_as_restores_the_previous_tenant_when_it_throws(): void
    {
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();
        $this->context->set($first);

        try {
            $this->context->runAs($second, fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($first->id, $this->context->id());
    }

    public function test_run_without_tenant_clears_and_restores_the_bound_instance(): void
    {
        $tenant = Tenant::factory()->create(['page_gen_catalog' => 3]);
        $this->context->set($tenant);

        $seen = $this->context->runWithoutTenant(fn () => $this->context->current());

        $this->assertNull($seen);
        $this->assertSame(3, $this->context->current()->page_gen_catalog);
    }

    public function test_run_without_tenant_leaves_no_tenant_bound_when_there_was_none(): void
    {
        $this->context->forget();

        $this->context->runWithoutTenant(fn () => null);

        $this->assertNull($this->context->current());
    }

    public function test_set_swaps_the_bound_instance_for_the_same_tenant(): void
    {
        $tenant = Tenant::factory()->create(['page_gen_catalog' => 1]);
        $this->context->set($tenant);

        Tenant::query()->whereKey($tenant->id)->update(['page_gen_catalog' => 5]);
        $this->context->set(Tenant::query()->find($tenant->id));

        $this->assertSame(5, $this->context->current()->page_gen_catalog);
    }
}
