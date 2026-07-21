<?php

namespace Tests\Feature\Core;

use App\Core\Sequences\SequenceService;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private SequenceService $sequences;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sequences = app(SequenceService::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_numbers_are_contiguous(): void
    {
        $tenant = Tenant::factory()->create();

        $numbers = $this->context->runAs($tenant, fn () => [
            $this->sequences->next('orders'),
            $this->sequences->next('orders'),
            $this->sequences->next('orders'),
        ]);

        $this->assertSame(['1', '2', '3'], $numbers);
    }

    public function test_series_are_independent(): void
    {
        $tenant = Tenant::factory()->create();

        $this->context->runAs($tenant, function (): void {
            $this->assertSame('1', $this->sequences->next('orders'));
            $this->assertSame('1', $this->sequences->next('invoices'));
            $this->assertSame('2', $this->sequences->next('orders'));
        });
    }

    public function test_numbering_is_per_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->context->runAs($a, fn () => $this->sequences->next('orders'));
        $this->context->runAs($a, fn () => $this->sequences->next('orders'));

        // Tenant B starts from 1, unaffected by A.
        $this->assertSame('1', $this->context->runAs($b, fn () => $this->sequences->next('orders')));
    }

    public function test_prefix_is_applied(): void
    {
        $tenant = Tenant::factory()->create();

        $number = $this->context->runAs($tenant, function (): string {
            $this->sequences->configure('invoices', 'FV2026-', 1);

            return $this->sequences->next('invoices');
        });

        $this->assertSame('FV2026-1', $number);
    }

    public function test_configure_sets_the_starting_number(): void
    {
        $tenant = Tenant::factory()->create();

        $number = $this->context->runAs($tenant, function (): string {
            $this->sequences->configure('invoices', '', 2026001);

            return $this->sequences->next('invoices');
        });

        $this->assertSame('2026001', $number);
    }

    public function test_reconfigure_does_not_rewind_an_advanced_series(): void
    {
        $tenant = Tenant::factory()->create();

        $next = $this->context->runAs($tenant, function (): string {
            // Series runs: 1, 2 issued.
            $this->sequences->next('orders');
            $this->sequences->next('orders');

            // A module deactivate/reactivate cycle re-runs configure(); it must
            // not reset the counter and reissue a number already in the books.
            $this->sequences->configure('orders', prefix: '', startAt: 1);

            return $this->sequences->next('orders');
        });

        $this->assertSame('3', $next);
    }

    public function test_reconfigure_refreshes_the_prefix(): void
    {
        $tenant = Tenant::factory()->create();

        $number = $this->context->runAs($tenant, function (): string {
            $this->sequences->configure('invoices', 'OLD-', 1);
            $this->sequences->next('invoices');

            // Renaming the prefix is safe — it does not collide with past numbers.
            $this->sequences->configure('invoices', 'NEW-', 1);

            return $this->sequences->next('invoices');
        });

        $this->assertSame('NEW-2', $number);
    }

    public function test_without_a_tenant_throws(): void
    {
        $this->expectException(MissingTenantContext::class);

        $this->sequences->next('orders');
    }
}
