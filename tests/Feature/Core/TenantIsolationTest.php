<?php

namespace Tests\Feature\Core;

use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantScopedFixture;
use Tests\TestCase;

/**
 * The reason this wave exists. Every assertion here stands between one
 * tenant's data and another tenant's screen.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_scoped_fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->unsignedInteger('amount')->default(0);
            $table->index(['tenant_id', 'label']);
        });

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);

        $this->context->runAs($this->tenantA, fn () => TenantScopedFixture::create(['label' => 'a-one', 'amount' => 100]));
        $this->context->runAs($this->tenantA, fn () => TenantScopedFixture::create(['label' => 'a-two', 'amount' => 200]));
        $this->context->runAs($this->tenantB, fn () => TenantScopedFixture::create(['label' => 'b-one', 'amount' => 999]));
    }

    public function test_listing_only_returns_own_rows(): void
    {
        $labels = $this->context->runAs($this->tenantA, fn () => TenantScopedFixture::pluck('label')->all());

        $this->assertEqualsCanonicalizing(['a-one', 'a-two'], $labels);
    }

    public function test_find_cannot_reach_across_tenants(): void
    {
        $foreignId = $this->context->runAs($this->tenantB, fn () => TenantScopedFixture::first()->id);

        $found = $this->context->runAs($this->tenantA, fn () => TenantScopedFixture::find($foreignId));

        $this->assertNull($found, 'Tenant A must not load a row belonging to tenant B by id.');
    }

    public function test_update_cannot_reach_across_tenants(): void
    {
        $foreignId = $this->context->runAs($this->tenantB, fn () => TenantScopedFixture::first()->id);

        $affected = $this->context->runAs(
            $this->tenantA,
            fn () => TenantScopedFixture::where('id', $foreignId)->update(['amount' => 0])
        );

        $this->assertSame(0, $affected);
        $this->assertSame(999, $this->context->runAs($this->tenantB, fn () => TenantScopedFixture::first()->amount));
    }

    public function test_delete_cannot_reach_across_tenants(): void
    {
        $foreignId = $this->context->runAs($this->tenantB, fn () => TenantScopedFixture::first()->id);

        $deleted = $this->context->runAs(
            $this->tenantA,
            fn () => TenantScopedFixture::where('id', $foreignId)->delete()
        );

        $this->assertSame(0, $deleted);
        $this->assertSame(1, $this->context->runAs($this->tenantB, fn () => TenantScopedFixture::count()));
    }

    public function test_aggregates_stop_at_the_tenant_boundary(): void
    {
        // Aggregates leak quietly: a wrong SUM looks like a number, not an error.
        $sum = $this->context->runAs($this->tenantA, fn () => TenantScopedFixture::sum('amount'));

        $this->assertSame(300, (int) $sum);
    }

    public function test_tenant_id_is_filled_from_context_on_create(): void
    {
        $row = $this->context->runAs($this->tenantA, fn () => TenantScopedFixture::create(['label' => 'fresh']));

        $this->assertSame($this->tenantA->id, $row->tenant_id);
    }

    public function test_explicit_foreign_tenant_id_is_overwritten_not_honoured(): void
    {
        // Mass assignment of tenant_id is the classic way this goes wrong:
        // a stray request field must never place a row in another tenant.
        $row = $this->context->runAs(
            $this->tenantA,
            fn () => TenantScopedFixture::create(['label' => 'hijack', 'tenant_id' => $this->tenantB->id])
        );

        $this->assertSame($this->tenantA->id, $row->fresh()->tenant_id);
    }

    public function test_query_without_context_throws_instead_of_returning_everything(): void
    {
        // Silent failure here would hand every tenant's rows to whoever asked.
        $this->expectException(MissingTenantContext::class);

        TenantScopedFixture::all();
    }

    public function test_create_without_context_throws(): void
    {
        $this->expectException(MissingTenantContext::class);

        TenantScopedFixture::create(['label' => 'orphan']);
    }

    public function test_explicit_escape_hatch_sees_all_tenants(): void
    {
        // System jobs need this; it is deliberately verbose so it stands out
        // in review and in grep.
        $all = TenantScopedFixture::withoutGlobalScope(TenantScope::class)->count();

        $this->assertSame(3, $all);
    }

    public function test_scope_applies_to_relations_too(): void
    {
        $count = $this->context->runAs(
            $this->tenantA,
            fn () => TenantScopedFixture::where('amount', '>', 0)->count()
        );

        $this->assertSame(2, $count);
    }
}
