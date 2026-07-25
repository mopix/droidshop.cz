<?php

namespace Tests\Feature\Storefront;

use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;
use Modules\Storefront\Support\DefaultHomepage;
use Tests\TestCase;

class HomepageSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_blocks_are_scoped_to_the_current_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        app(TenantContext::class)->runAs($a, fn () => HomepageBlock::create([
            'position' => 0, 'type' => BlockType::Text, 'payload' => ['html' => 'A'], 'visible' => true,
        ]));

        $seenByB = app(TenantContext::class)->runAs($b, fn () => HomepageBlock::count());
        $seenByA = app(TenantContext::class)->runAs($a, fn () => HomepageBlock::count());

        $this->assertSame(0, $seenByB);
        $this->assertSame(1, $seenByA);
    }

    public function test_seeds_a_default_homepage_when_a_tenant_is_provisioned(): void
    {
        $owner = User::factory()->create();
        $plan = Plan::factory()->create();

        $tenant = app(TenantProvisioner::class)->provision($owner, 'Test Shop', 'testshop', $plan);

        $blocks = app(TenantContext::class)->runAs(
            $tenant,
            fn () => HomepageBlock::query()->orderBy('position')->pluck('type'),
        );

        $this->assertSame(
            ['hero', 'product_row', 'category_grid'],
            $blocks->map(fn (BlockType $type) => $type->value)->all(),
        );
    }

    public function test_does_not_seed_twice(): void
    {
        $tenant = Tenant::factory()->create();
        $seeder = app(DefaultHomepage::class);

        $seeder->seed($tenant);
        $seeder->seed($tenant);

        $count = app(TenantContext::class)->runAs($tenant, fn () => HomepageBlock::count());

        $this->assertSame(3, $count);
    }
}
