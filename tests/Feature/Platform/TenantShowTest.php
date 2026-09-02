<?php

namespace Tests\Feature\Platform;

use App\Core\Services\AuditLog;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

class TenantShowTest extends TestCase
{
    use ActivatesModules;
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
        $this->actingAsPlatformAdmin();

        // The storage limit is measured off the real disk, and tenant ids are
        // reused across runs — a leftover directory from an earlier test would
        // otherwise report megabytes this tenant never stored.
        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);
    }

    private function detailUrl(Tenant $tenant): string
    {
        return $this->platformUrl('/superadmin/tenanti/'.$tenant->uuid);
    }

    public function test_detail_is_addressed_by_uuid_not_by_id(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Kolo Shop']);

        $this->get($this->detailUrl($tenant))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Tenants/Show')
                ->where('tenant.name', 'Kolo Shop')
            );

        $this->get($this->platformUrl('/superadmin/tenanti/'.$tenant->id))->assertNotFound();
    }

    public function test_unknown_tenant_is_not_found(): void
    {
        $this->get($this->platformUrl('/superadmin/tenanti/00000000-0000-0000-0000-000000000000'))
            ->assertNotFound();
    }

    public function test_detail_lists_domains_and_users(): void
    {
        $tenant = Tenant::factory()->withDomain('kolo.droidshop')->create();
        $user = User::factory()->create(['name' => 'Jana Nováková']);
        $tenant->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);

        $this->get($this->detailUrl($tenant))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('domains.0.domain', 'kolo.droidshop')
                ->where('domains.0.is_primary', true)
                ->where('users.0.name', 'Jana Nováková')
                ->where('users.0.role', 'owner')
            );
    }

    public function test_detail_reports_module_state_for_this_tenant_only(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        Module::factory()->create(['key' => 'blog', 'core' => false]);
        Module::factory()->create(['key' => 'newsletter', 'core' => false]);
        $this->activateModule($tenant, 'blog');
        $this->activateModule($other, 'newsletter');

        $this->get($this->detailUrl($tenant))
            ->assertOk()
            ->assertInertia(function ($page) {
                $modules = collect($page->toArray()['props']['modules'])->keyBy('key');

                $this->assertTrue($modules['blog']['enabled']);
                $this->assertFalse(
                    $modules['newsletter']['enabled'],
                    'A module another tenant switched on must not show as enabled here.'
                );
            });
    }

    public function test_detail_reports_limit_usage(): void
    {
        $tenant = Tenant::factory()->create();
        $tenant->plan->update(['limits' => ['products' => 100, 'storage_mb' => 500]]);

        $this->get($this->detailUrl($tenant))
            ->assertOk()
            ->assertInertia(function ($page) {
                $limits = collect($page->toArray()['props']['limits'])->keyBy('key');

                $this->assertSame(100, $limits['products']['cap']);
                $this->assertSame(500, $limits['storage_mb']['cap']);
                $this->assertSame(0, $limits['storage_mb']['used']);
            });
    }

    public function test_detail_shows_the_audit_trail_of_this_tenant_only(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $context = app(TenantContext::class);
        $audit = app(AuditLog::class);

        $context->runAs($tenant, fn () => $audit->log('tenant.inspected'));
        $context->runAs($other, fn () => $audit->log('tenant.other_thing'));

        $this->get($this->detailUrl($tenant))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('audit', 1)
                ->where('audit.0.action', 'tenant.inspected')
            );
    }

    public function test_detail_does_not_exist_on_a_tenant_host(): void
    {
        $tenant = Tenant::factory()->withDomain('kolo.droidshop')->create();

        $this->get('http://kolo.droidshop/superadmin/tenanti/'.$tenant->uuid)->assertNotFound();
    }

    public function test_superadmin_can_queue_a_data_export(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $tenant = Tenant::factory()->create();

        $this->post($this->platformUrl('/superadmin/tenanti/'.$tenant->uuid.'/export'))
            ->assertRedirect();

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\ExportTenantData::class,
            fn ($job): bool => $job->tenantId === $tenant->id,
        );
    }

    public function test_a_second_export_for_the_same_tenant_is_refused(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $tenant = Tenant::factory()->create();

        $this->post($this->platformUrl('/superadmin/tenanti/'.$tenant->uuid.'/export'));

        $this->post($this->platformUrl('/superadmin/tenanti/'.$tenant->uuid.'/export'))
            ->assertSessionHasErrors('export');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ExportTenantData::class, 1);
    }

    public function test_the_detail_reports_the_last_export(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantContext::class)->runAs($tenant, fn () => \App\Models\JobLogEntry::create([
            'type' => \App\Models\JobLogEntry::TYPE_EXPORT,
            'status' => \App\Models\JobLogEntry::STATUS_FINISHED,
            'progress' => 100,
            'report' => ['rows' => 12, 'files' => 3],
            'created_at' => now(),
            'finished_at' => now(),
        ]));

        $this->get($this->detailUrl($tenant))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('export.status', 'finished')
                ->where('export.running', false));
    }
}
