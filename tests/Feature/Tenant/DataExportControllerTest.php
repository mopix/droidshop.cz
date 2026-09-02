<?php

namespace Tests\Feature\Tenant;

use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Jobs\ExportTenantData;
use App\Models\Domain;
use App\Models\JobLogEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DataExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function ownerOnHost(string $subdomain = 'shop'): array
    {
        $tenant = Tenant::factory()->create();
        Domain::create([
            'tenant_id' => $tenant->id,
            'domain' => $subdomain.'.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    private function host(string $subdomain = 'shop'): string
    {
        return 'http://'.$subdomain.'.'.config('tenancy.platform_domain');
    }

    public function test_the_owner_sees_the_export_screen(): void
    {
        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->get($this->host().'/admin/nastaveni/export')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Settings/DataExport')
                ->where('latest', null));
    }

    public function test_staff_cannot_reach_the_export(): void
    {
        [$tenant] = $this->ownerOnHost();

        $staff = User::factory()->create();
        $tenant->users()->attach($staff, ['role' => 'staff', 'joined_at' => now()]);

        // The archive holds every customer's personal data. Phase-2 staff do
        // not get it just by being members of the shop.
        $this->actingAs($staff)
            ->get($this->host().'/admin/nastaveni/export')
            ->assertForbidden();
    }

    public function test_a_member_of_another_shop_cannot_reach_it(): void
    {
        $this->ownerOnHost();

        $other = Tenant::factory()->create();
        $stranger = User::factory()->create();
        $other->users()->attach($stranger, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($stranger)
            ->get($this->host().'/admin/nastaveni/export')
            ->assertForbidden();
    }

    public function test_requesting_an_export_queues_it(): void
    {
        Queue::fake();

        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/export')
            ->assertRedirect();

        Queue::assertPushed(ExportTenantData::class);

        $entry = app(TenantContext::class)->runAs($tenant, fn () => JobLogEntry::first());
        $this->assertSame(JobLogEntry::TYPE_EXPORT, $entry->type);
    }

    public function test_a_second_request_while_one_runs_is_refused_with_a_message(): void
    {
        Queue::fake();

        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)->post($this->host().'/admin/nastaveni/export');

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/export')
            ->assertSessionHasErrors('export');

        Queue::assertPushed(ExportTenantData::class, 1);
    }

    public function test_a_finished_export_offers_a_signed_download_link(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        $context = app(TenantContext::class);

        $context->runAs($tenant, function (): void {
            app(FileStorage::class)->putPrivateUnmetered('exports/hotovo.zip', 'zip-bytes');

            JobLogEntry::create([
                'type' => JobLogEntry::TYPE_EXPORT,
                'status' => JobLogEntry::STATUS_FINISHED,
                'progress' => 100,
                'report' => ['path' => 'exports/hotovo.zip', 'rows' => 3],
                'created_at' => now(),
                'finished_at' => now(),
            ]);
        });

        $this->actingAs($owner)
            ->get($this->host().'/admin/nastaveni/export')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('latest.status', JobLogEntry::STATUS_FINISHED)
                ->where('latest.running', false)
                ->where('latest.downloadUrl', fn (?string $url): bool => str_contains((string) $url, '/admin/nastaveni/export/')));
    }

    public function test_a_missing_archive_offers_no_link(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        // The row outlives the file once retention cleans up. The screen must
        // say so rather than hand out a link that 404s.
        app(TenantContext::class)->runAs($tenant, fn () => JobLogEntry::create([
            'type' => JobLogEntry::TYPE_EXPORT,
            'status' => JobLogEntry::STATUS_FINISHED,
            'progress' => 100,
            'report' => ['path' => 'exports/pryc.zip'],
            'created_at' => now(),
            'finished_at' => now(),
        ]));

        $this->actingAs($owner)
            ->get($this->host().'/admin/nastaveni/export')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('latest.downloadUrl', null));
    }

    public function test_the_archive_cannot_be_downloaded_without_a_session(): void
    {
        [$tenant] = $this->ownerOnHost();
        $context = app(TenantContext::class);

        $job = $context->runAs($tenant, function () {
            app(FileStorage::class)->putPrivateUnmetered('exports/hotovo.zip', 'zip-bytes');

            return JobLogEntry::create([
                'type' => JobLogEntry::TYPE_EXPORT,
                'status' => JobLogEntry::STATUS_FINISHED,
                'progress' => 100,
                'report' => ['path' => 'exports/hotovo.zip'],
                'created_at' => now(),
                'finished_at' => now(),
            ]);
        });

        // The whole point of not using a signed capability URL: the link alone
        // is worthless.
        $this->get($this->host().'/admin/nastaveni/export/'.$job->id)
            ->assertRedirect();
    }

    public function test_an_owner_cannot_download_another_shops_archive(): void
    {
        [, $owner] = $this->ownerOnHost();

        $other = Tenant::factory()->create();
        $foreignJob = app(TenantContext::class)->runAs($other, function () {
            app(FileStorage::class)->putPrivateUnmetered('exports/cizi.zip', 'zip-bytes');

            return JobLogEntry::create([
                'type' => JobLogEntry::TYPE_EXPORT,
                'status' => JobLogEntry::STATUS_FINISHED,
                'progress' => 100,
                'report' => ['path' => 'exports/cizi.zip'],
                'created_at' => now(),
                'finished_at' => now(),
            ]);
        });

        $this->actingAs($owner)
            ->get($this->host().'/admin/nastaveni/export/'.$foreignJob->id)
            ->assertNotFound();
    }

    public function test_the_owner_downloads_the_archive(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $job = app(TenantContext::class)->runAs($tenant, function () {
            app(FileStorage::class)->putPrivateUnmetered('exports/hotovo.zip', 'zip-bytes');

            return JobLogEntry::create([
                'type' => JobLogEntry::TYPE_EXPORT,
                'status' => JobLogEntry::STATUS_FINISHED,
                'progress' => 100,
                'report' => ['path' => 'exports/hotovo.zip'],
                'created_at' => now(),
                'finished_at' => now(),
            ]);
        });

        $this->actingAs($owner)
            ->get($this->host().'/admin/nastaveni/export/'.$job->id)
            ->assertOk()
            ->assertDownload();
    }

    public function test_the_screen_never_shows_another_shops_export(): void
    {
        [, $owner] = $this->ownerOnHost();

        $other = Tenant::factory()->create();
        app(TenantContext::class)->runAs($other, fn () => JobLogEntry::create([
            'type' => JobLogEntry::TYPE_EXPORT,
            'status' => JobLogEntry::STATUS_FINISHED,
            'progress' => 100,
            'report' => ['path' => 'exports/cizi.zip'],
            'created_at' => now(),
            'finished_at' => now(),
        ]));

        $this->actingAs($owner)
            ->get($this->host().'/admin/nastaveni/export')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('latest', null));
    }
}
