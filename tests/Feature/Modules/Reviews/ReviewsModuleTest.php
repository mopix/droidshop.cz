<?php

namespace Tests\Feature\Modules\Reviews;

use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Reviews\Models\Review;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ReviewsModuleTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();
    }

    public function test_manifest_is_registered_at_base_level(): void
    {
        $module = Module::query()->where('key', 'reviews')->firstOrFail();

        // Module::level is cast to the PlanLevel enum (app/Models/Module.php),
        // so the underlying string lives on ->value — same convention as
        // DiscountModuleTest and FeedModuleTest.
        $this->assertSame('base', $module->level->value);
        $this->assertFalse((bool) $module->core);
    }

    public function test_tables_exist(): void
    {
        foreach (['reviews', 'review_aggregates', 'review_invitations', 'review_optouts'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Chybí tabulka {$table}");
        }
    }

    public function test_shop_review_uses_zero_not_null_so_the_unique_index_bites(): void
    {
        $tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        // BelongsToTenant stamps tenant_id from the ambient context and throws
        // MissingTenantContext when there is none — a passed-in tenant_id is
        // never trusted. Every write below therefore runs inside runAs().
        app(TenantContext::class)->set($tenant);

        Review::query()->create([
            'subject' => 'shop',
            'product_id' => 0,
            'order_id' => 1,
            'author_name' => 'Jan',
            'author_email' => 'jan@example.com',
            'rating' => 5,
            'status' => 'pending',
        ]);

        $this->expectException(QueryException::class);

        Review::query()->create([
            'subject' => 'shop',
            'product_id' => 0,
            'order_id' => 1,
            'author_name' => 'Jan',
            'author_email' => 'jan@example.com',
            'rating' => 1,
            'status' => 'pending',
        ]);
    }
}
