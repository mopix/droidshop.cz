<?php

namespace Tests\Feature\Core;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_context_starts_empty(): void
    {
        $this->assertNull($this->context->current());
        $this->assertNull($this->context->id());
        $this->assertFalse($this->context->check());
    }

    public function test_set_makes_tenant_current(): void
    {
        $tenant = Tenant::factory()->create();

        $this->context->set($tenant);

        $this->assertSame($tenant->id, $this->context->id());
        $this->assertTrue($this->context->check());
    }

    public function test_run_as_restores_previous_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->context->set($a);

        $seen = $this->context->runAs($b, fn () => $this->context->id());

        $this->assertSame($b->id, $seen, 'Callback should run as tenant B.');
        $this->assertSame($a->id, $this->context->id(), 'Tenant A must be current again.');
    }

    public function test_run_as_restores_context_even_when_callback_throws(): void
    {
        // The important one. A throwing callback that left a foreign tenant
        // current would turn one failed job into cross-tenant writes for
        // everything that ran after it on the same worker.
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->context->set($a);

        try {
            $this->context->runAs($b, function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('Exception should have propagated.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($a->id, $this->context->id());
    }

    public function test_run_as_clears_context_when_there_was_none(): void
    {
        $tenant = Tenant::factory()->create();

        $this->context->runAs($tenant, fn () => null);

        $this->assertNull($this->context->current(), 'Context must return to empty, not stay on the tenant.');
    }

    public function test_run_without_tenant_suspends_and_restores_context(): void
    {
        $tenant = Tenant::factory()->create();
        $this->context->set($tenant);

        $seen = $this->context->runWithoutTenant(fn () => $this->context->id());

        $this->assertNull($seen, 'Platform work must see no tenant.');
        $this->assertSame($tenant->id, $this->context->id());
    }

    public function test_run_without_tenant_restores_context_when_callback_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $this->context->set($tenant);

        try {
            $this->context->runWithoutTenant(function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('Exception should have propagated.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($tenant->id, $this->context->id());
    }
}
