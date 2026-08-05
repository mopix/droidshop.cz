<?php

namespace Tests\Feature\PageCache;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationsTest extends TestCase
{
    use RefreshDatabase;

    private Generations $generations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->generations = app(Generations::class);
    }

    public function test_a_fresh_tenant_starts_at_generation_one(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame('1.1', $this->generations->stamp($tenant, [Dimension::Catalog, Dimension::Theme]));
    }

    public function test_bumping_one_dimension_leaves_the_others_alone(): void
    {
        $tenant = Tenant::factory()->create();

        $this->generations->bump($tenant, Dimension::Catalog);

        $this->assertSame('2', $this->generations->stamp($tenant, [Dimension::Catalog]));
        $this->assertSame('1', $this->generations->stamp($tenant, [Dimension::Theme]));
        $this->assertSame('1', $this->generations->stamp($tenant, [Dimension::Content]));
    }

    public function test_the_stamp_follows_the_order_the_dimensions_were_asked_for(): void
    {
        $tenant = Tenant::factory()->create();

        $this->generations->bump($tenant, Dimension::Theme);

        $this->assertSame('1.2', $this->generations->stamp($tenant, [Dimension::Catalog, Dimension::Theme]));
        $this->assertSame('2.1', $this->generations->stamp($tenant, [Dimension::Theme, Dimension::Catalog]));
    }

    public function test_bump_all_moves_every_dimension(): void
    {
        $tenant = Tenant::factory()->create();

        $this->generations->bumpAll($tenant);

        $this->assertSame('2.2.2', $this->generations->stamp(
            $tenant,
            [Dimension::Catalog, Dimension::Content, Dimension::Theme],
        ));
    }

    public function test_a_bump_is_visible_on_the_instance_that_triggered_it(): void
    {
        $tenant = Tenant::factory()->create();

        $this->generations->bump($tenant, Dimension::Catalog);
        $this->generations->bump($tenant, Dimension::Catalog);

        // Without refreshing the in-memory attribute the second bump would
        // read a stale 1 and the stamp would lag a request behind the data.
        $this->assertSame('3', $this->generations->stamp($tenant, [Dimension::Catalog]));
    }

    public function test_one_tenants_bump_does_not_move_another(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->generations->bump($a, Dimension::Catalog);

        $this->assertSame('1', $this->generations->stamp($b, [Dimension::Catalog]));
    }
}
