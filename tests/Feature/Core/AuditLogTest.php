<?php

namespace Tests\Feature\Core;

use App\Core\Enums\TenantStatus;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use App\Models\AuditLogEntry;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private AuditLog $audit;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
        $this->audit = app(AuditLog::class);
    }

    public function test_entry_picks_up_the_current_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $entry = $this->context->runAs($tenant, fn () => $this->audit->log('product.deleted'));

        $this->assertSame($tenant->id, $entry->tenant_id);
    }

    public function test_entry_picks_up_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = $this->audit->log('settings.updated');

        $this->assertSame($user->id, $entry->user_id);
    }

    public function test_platform_action_records_without_tenant_or_user(): void
    {
        $entry = $this->audit->log('platform.maintenance_started');

        $this->assertNull($entry->tenant_id);
        $this->assertNull($entry->user_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'platform.maintenance_started']);
    }

    public function test_entry_records_the_acting_platform_admin(): void
    {
        // Superadmins live on their own guard, so auth()->id() would leave the
        // entry anonymous — the identity has to be picked up explicitly.
        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform');

        $entry = $this->audit->log('tenant.status_changed');

        $this->assertNull($entry->user_id);
        $this->assertSame($admin->id, $entry->meta['platform_admin_id']);
        $this->assertSame($admin->email, $entry->meta['platform_admin_email']);
    }

    public function test_platform_admin_identity_does_not_overwrite_caller_meta(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform');

        $entry = $this->audit->log('module.globally_disabled', null, ['reason' => 'security incident']);

        $this->assertSame('security incident', $entry->meta['reason']);
        $this->assertSame($admin->id, $entry->meta['platform_admin_id']);
    }

    public function test_tenant_user_entry_carries_no_platform_admin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = $this->audit->log('settings.updated');

        $this->assertSame($user->id, $entry->user_id);
        $this->assertArrayNotHasKey('platform_admin_id', $entry->meta ?? []);
    }

    public function test_subject_is_recorded_polymorphically(): void
    {
        $tenant = Tenant::factory()->create();

        $entry = $this->audit->log('tenant.inspected', $tenant, ['note' => 'support call']);

        $this->assertSame($tenant->getMorphClass(), $entry->subject_type);
        $this->assertSame($tenant->id, (int) $entry->subject_id);
        $this->assertSame(['note' => 'support call'], $entry->meta);
        $this->assertTrue($entry->fresh()->subject->is($tenant));
    }

    public function test_status_change_is_audited(): void
    {
        // Spec §6.0: every lifecycle transition has to leave a trace.
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

        $this->context->runAs($tenant, fn () => $tenant->changeStatus(TenantStatus::Suspended, 'unpaid invoice'));

        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()->status);
        $this->assertNotNull($tenant->fresh()->suspended_at);

        $entry = AuditLogEntry::where('action', 'tenant.status_changed')->firstOrFail();

        $this->assertSame($tenant->id, $entry->tenant_id);
        $this->assertSame('active', $entry->meta['from']);
        $this->assertSame('suspended', $entry->meta['to']);
        $this->assertSame('unpaid invoice', $entry->meta['reason']);
    }

    public function test_status_change_to_the_same_status_records_nothing(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

        $this->context->runAs($tenant, fn () => $tenant->changeStatus(TenantStatus::Active));

        $this->assertSame(0, AuditLogEntry::count());
    }

    public function test_pending_deletion_stamps_the_request_time(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);

        $this->context->runAs($tenant, fn () => $tenant->changeStatus(TenantStatus::PendingDeletion));

        $this->assertNotNull($tenant->fresh()->deletion_requested_at);
    }
}
