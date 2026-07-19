<?php

namespace Tests\Feature\Core;

use App\Core\Features\FeatureFlags;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    private FeatureFlags $flags;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flags = app(FeatureFlags::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_unknown_flag_is_off(): void
    {
        $this->assertFalse($this->flags->enabled('nonexistent'));
    }

    public function test_boolean_flag(): void
    {
        config()->set('features.simple', true);

        $this->assertTrue($this->flags->enabled('simple'));
    }

    public function test_globally_enabled_definition(): void
    {
        config()->set('features.rollout', ['enabled' => true]);

        $this->assertTrue($this->flags->enabled('rollout', Tenant::factory()->create()));
    }

    public function test_tenant_whitelist(): void
    {
        $target = Tenant::factory()->create();
        $other = Tenant::factory()->create();

        config()->set('features.beta', ['enabled' => false, 'tenants' => [$target->id]]);

        $this->assertTrue($this->flags->enabled('beta', $target));
        $this->assertFalse($this->flags->enabled('beta', $other));
    }

    public function test_percentage_is_deterministic_for_a_tenant(): void
    {
        // The same tenant must get the same answer every time; a flag that
        // flickers between requests is unusable.
        $tenant = Tenant::factory()->create();

        config()->set('features.gradual', ['percentage' => 50]);

        $first = $this->flags->enabled('gradual', $tenant);

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($first, $this->flags->enabled('gradual', $tenant));
        }
    }

    public function test_percentage_zero_is_off_for_everyone(): void
    {
        config()->set('features.gradual', ['percentage' => 0]);

        foreach (Tenant::factory()->count(10)->create() as $tenant) {
            $this->assertFalse($this->flags->enabled('gradual', $tenant));
        }
    }

    public function test_percentage_hundred_is_on_for_everyone(): void
    {
        config()->set('features.gradual', ['percentage' => 100]);

        foreach (Tenant::factory()->count(10)->create() as $tenant) {
            $this->assertTrue($this->flags->enabled('gradual', $tenant));
        }
    }

    public function test_percentage_splits_the_population(): void
    {
        // Not an exact count — hashing is not perfectly uniform on small n —
        // but a 50% flag must clearly land somewhere in the middle, not all or
        // nothing.
        config()->set('features.gradual', ['percentage' => 50]);

        $on = 0;
        $tenants = Tenant::factory()->count(60)->create();

        foreach ($tenants as $tenant) {
            if ($this->flags->enabled('gradual', $tenant)) {
                $on++;
            }
        }

        $this->assertGreaterThan(10, $on);
        $this->assertLessThan(50, $on);
    }

    public function test_flag_uses_current_tenant_when_none_passed(): void
    {
        $tenant = Tenant::factory()->create();
        config()->set('features.beta', ['tenants' => [$tenant->id]]);

        $enabled = $this->context->runAs($tenant, fn () => $this->flags->enabled('beta'));

        $this->assertTrue($enabled);
    }
}
