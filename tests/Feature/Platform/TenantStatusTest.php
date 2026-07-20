<?php

namespace Tests\Feature\Platform;

use App\Core\Enums\TenantStatus;
use App\Models\AuditLogEntry;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

class TenantStatusTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
        $this->admin = $this->actingAsPlatformAdmin();
    }

    private function statusUrl(Tenant $tenant): string
    {
        return $this->platformUrl('/superadmin/tenanti/'.$tenant->uuid.'/stav');
    }

    public function test_suspending_records_the_status_the_time_and_the_reason(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

        $this->patch($this->statusUrl($tenant), [
            'status' => 'suspended',
            'reason' => 'nezaplacené faktury 3 měsíce',
        ])->assertRedirect();

        $tenant->refresh();
        $this->assertSame(TenantStatus::Suspended, $tenant->status);
        $this->assertNotNull($tenant->suspended_at);

        $entry = AuditLogEntry::where('action', 'tenant.status_changed')->firstOrFail();
        $this->assertSame($tenant->id, $entry->tenant_id);
        $this->assertSame('nezaplacené faktury 3 měsíce', $entry->meta['reason']);
        $this->assertSame($this->admin->id, $entry->meta['platform_admin_id']);
    }

    public function test_suspending_without_a_reason_is_rejected(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

        $this->patch($this->statusUrl($tenant), ['status' => 'suspended', 'reason' => '  '])
            ->assertSessionHasErrors('reason');

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
    }

    public function test_a_suspended_shop_stops_serving_its_storefront(): void
    {
        $tenant = Tenant::factory()->withDomain('kolo.droidshop')->create(['status' => TenantStatus::Active]);

        $this->patch($this->statusUrl($tenant), ['status' => 'suspended', 'reason' => 'porušení podmínek']);

        $this->get('http://kolo.droidshop/')->assertStatus(503);
    }

    public function test_resuming_puts_the_shop_back(): void
    {
        $tenant = Tenant::factory()->suspended()->create();

        $this->patch($this->statusUrl($tenant), ['status' => 'active'])->assertRedirect();

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
    }

    public function test_pending_deletion_requires_a_reason_and_stamps_the_request(): void
    {
        $tenant = Tenant::factory()->suspended()->create();

        $this->patch($this->statusUrl($tenant), ['status' => 'pending_deletion'])
            ->assertSessionHasErrors('reason');

        $this->patch($this->statusUrl($tenant), [
            'status' => 'pending_deletion',
            'reason' => 'na žádost zákazníka',
        ])->assertRedirect();

        $this->assertNotNull($tenant->fresh()->deletion_requested_at);
    }

    public function test_an_impossible_transition_is_refused(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Deleted]);

        $this->patch($this->statusUrl($tenant), ['status' => 'trial'])
            ->assertSessionHasErrors('status');

        $this->assertSame(TenantStatus::Deleted, $tenant->fresh()->status);
    }

    public function test_deleted_cannot_be_set_by_hand(): void
    {
        // Only the deletion job may set it: the status and the data have to
        // agree, and a person clicking it would make them disagree.
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PendingDeletion]);

        $this->patch($this->statusUrl($tenant), ['status' => 'deleted', 'reason' => 'hotovo'])
            ->assertSessionHasErrors('status');
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $tenant = Tenant::factory()->create();

        $this->patch($this->statusUrl($tenant), ['status' => 'nonsense'])
            ->assertSessionHasErrors('status');
    }

    public function test_a_tenant_user_cannot_change_a_status(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        auth('platform')->logout();
        // Explicit guard: actingAs with a guard makes it the default one, so an
        // unqualified call here would put the tenant user on the platform guard.
        $this->actingAs(User::factory()->create(), 'web');

        $this->patch($this->statusUrl($tenant), ['status' => 'suspended', 'reason' => 'pokus'])
            ->assertRedirect(route('platform.login'));

        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
    }
}
