<?php

namespace Tests\Feature\Modules\Feeds;

use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Feeds\Models\ProductFeed;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class FeedModuleTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->artisan('modules:sync')->assertSuccessful();
    }

    public function test_the_manifest_registers_a_base_module(): void
    {
        $module = Module::find('feeds');

        $this->assertNotNull($module);
        $this->assertFalse($module->core);
        // Base, not premium: a Heureka feed is a condition of selling in the
        // Czech market, not an upsell.
        $this->assertSame('base', $module->level->value);
        $this->assertSame(['feeds.manage'], $module->manifest['permissions']);
    }

    public function test_the_tables_exist(): void
    {
        foreach (['tenant_id', 'type', 'enabled', 'settings'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('product_feeds', $column),
                "product_feeds is missing {$column}",
            );
        }

        foreach (['tenant_id', 'category_id', 'type', 'category_text'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('feed_category_mappings', $column),
                "feed_category_mappings is missing {$column}",
            );
        }
    }

    public function test_a_feed_row_is_scoped_to_its_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $context = app(TenantContext::class);

        $context->runAs($a, fn () => ProductFeed::query()->create([
            'type' => ProductFeed::TYPE_HEUREKA,
            'enabled' => true,
        ]));

        $this->assertSame(1, $context->runAs($a, fn () => ProductFeed::query()->count()));
        $this->assertSame(0, $context->runAs($b, fn () => ProductFeed::query()->count()));
    }

    public function test_both_feed_types_are_known(): void
    {
        $this->assertSame(['heureka', 'zbozi'], ProductFeed::TYPES);
    }
}
