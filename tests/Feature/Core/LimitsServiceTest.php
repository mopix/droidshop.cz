<?php

namespace Tests\Feature\Core;

use App\Core\Limits\Contracts\LimitCounter;
use App\Core\Limits\LimitOutcome;
use App\Core\Limits\LimitsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LimitsServiceTest extends TestCase
{
    use RefreshDatabase;

    private LimitsService $limits;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limits = app(LimitsService::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    /**
     * A counter reporting a fixed usage, so limit checks are deterministic.
     */
    private function counter(string $limit, int $usage): void
    {
        $this->limits->registerCounter(new class($limit, $usage) implements LimitCounter
        {
            public function __construct(private string $limit, private int $usage) {}

            public function limit(): string
            {
                return $this->limit;
            }

            public function count(Tenant $tenant): int
            {
                return $this->usage;
            }
        });
    }

    private function tenantOnPlanWith(array $limits): Tenant
    {
        $plan = Plan::factory()->create(['limits' => $limits]);

        return Tenant::factory()->create(['plan_id' => $plan->id]);
    }

    public function test_allow_well_under_the_cap(): void
    {
        $this->counter('products', 10);
        $tenant = $this->tenantOnPlanWith(['products' => 100]);

        $result = $this->context->runAs($tenant, fn () => $this->limits->check('products'));

        $this->assertSame(LimitOutcome::Allow, $result->outcome);
        $this->assertTrue($result->allowed());
        $this->assertSame(90, $result->remaining());
    }

    public function test_warn_near_the_cap(): void
    {
        $this->counter('products', 79);
        $tenant = $this->tenantOnPlanWith(['products' => 100]);

        // 79 + 1 = 80 = 80% of 100.
        $result = $this->context->runAs($tenant, fn () => $this->limits->check('products'));

        $this->assertSame(LimitOutcome::Warn, $result->outcome);
        $this->assertTrue($result->allowed());
    }

    public function test_block_at_the_cap(): void
    {
        $this->counter('products', 100);
        $tenant = $this->tenantOnPlanWith(['products' => 100]);

        $result = $this->context->runAs($tenant, fn () => $this->limits->check('products'));

        $this->assertSame(LimitOutcome::Block, $result->outcome);
        $this->assertFalse($result->allowed());
        $this->assertSame(0, $result->remaining());
    }

    public function test_delta_greater_than_one_is_respected(): void
    {
        $this->counter('products', 90);
        $tenant = $this->tenantOnPlanWith(['products' => 100]);

        // A bulk import of 20 would overshoot.
        $result = $this->context->runAs($tenant, fn () => $this->limits->check('products', 20));

        $this->assertSame(LimitOutcome::Block, $result->outcome);
    }

    public function test_uncapped_limit_is_always_allowed(): void
    {
        $this->counter('products', 5000);
        $tenant = $this->tenantOnPlanWith(['storage_mb' => 2048]); // no products cap

        $result = $this->context->runAs($tenant, fn () => $this->limits->check('products'));

        $this->assertSame(LimitOutcome::Allow, $result->outcome);
        $this->assertNull($result->remaining());
    }

    public function test_tenant_without_a_plan_is_blocked(): void
    {
        // Not "unlimited": an interrupted onboarding must not hand out
        // everything for free.
        $this->counter('products', 0);
        $tenant = Tenant::factory()->create(['plan_id' => null]);

        $result = $this->context->runAs($tenant, fn () => $this->limits->check('products'));

        $this->assertSame(LimitOutcome::Block, $result->outcome);
    }

    public function test_no_tenant_context_is_blocked(): void
    {
        $result = $this->limits->check('products');

        $this->assertSame(LimitOutcome::Block, $result->outcome);
    }

    public function test_plan_module_override_wins_over_plan_default(): void
    {
        $this->counter('products', 600);
        $plan = Plan::factory()->create(['limits' => ['products' => 500]]);

        // A module bought on this plan raises the products cap to 1000.
        $module = Module::factory()->key('bulk')->create();
        $plan->modules()->attach($module->key, ['limits' => json_encode(['products' => 1000])]);

        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        $result = $this->context->runAs($tenant, fn () => $this->limits->check('products'));

        // 600 would be blocked by the plan default of 500, but allowed by the
        // override of 1000 (with a warning, since 601 >= 80% of 1000... no, 601
        // < 800, so allow).
        $this->assertSame(LimitOutcome::Allow, $result->outcome);
        $this->assertSame(1000, $result->cap);
    }

    public function test_usage_reads_the_registered_counter(): void
    {
        $this->counter('products', 42);
        $tenant = $this->tenantOnPlanWith(['products' => 100]);

        $usage = $this->context->runAs($tenant, fn () => $this->limits->usage('products'));

        $this->assertSame(42, $usage);
    }
}
