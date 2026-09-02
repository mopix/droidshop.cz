<?php

namespace Tests\Feature\Modules;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\UninstallModule;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Models\AuditLogEntry;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Discounts\Models\Discount;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;
use ZipArchive;

/**
 * Uninstall is the only irreversible thing a tenant can do to their own shop.
 *
 * Deactivation has always kept the data, deliberately. This is the other
 * operation, and every test here is about a way it could go wrong: deleting
 * the wrong tenant's rows, deleting a module the law says to keep, deleting
 * something the shop is still running, or deleting without a backup.
 */
class ModuleUninstallTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
    }

    private function shopWithDiscount(string $code): Tenant
    {
        $tenant = Tenant::factory()->create();
        $this->activateModule($tenant, 'discounts');

        $this->context->runAs($tenant, fn () => Discount::create([
            'name' => 'Sleva '.$code,
            'code' => $code,
            'type' => 'percent',
            'value' => 100,
            'active' => true,
        ]));

        return $tenant;
    }

    public function test_a_core_module_can_never_be_uninstalled(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectExceptionMessage('core module');

        app(ModuleRegistry::class)->uninstall($tenant, 'storefront');
    }

    public function test_a_module_holding_legal_records_does_not_support_uninstall(): void
    {
        $registry = app(ModuleRegistry::class);

        // Tax documents must be kept for ten years, and documents.order_id is
        // a foreign key into the orders they describe. Neither module declares
        // ModuleUninstall, and that is the safeguard — not a runtime check
        // someone can forget.
        $this->assertFalse($registry->supportsUninstall('docs'));
        $this->assertFalse($registry->supportsUninstall('orders'));

        $this->assertTrue($registry->supportsUninstall('discounts'));
    }

    public function test_a_running_module_cannot_be_uninstalled(): void
    {
        $tenant = $this->shopWithDiscount('SLEVA10');

        // Deactivation runs the dependency guards, so requiring it first means
        // reaching uninstall already proves nothing else needs the module.
        $this->expectExceptionMessage('must be switched off');

        app(ModuleRegistry::class)->uninstall($tenant, 'discounts');
    }

    public function test_uninstalling_deletes_the_modules_rows(): void
    {
        $tenant = $this->shopWithDiscount('SLEVA10');
        $registry = app(ModuleRegistry::class);

        $registry->deactivate($tenant, 'discounts');
        $deleted = $registry->uninstall($tenant, 'discounts');

        $this->assertSame(1, $deleted['discounts']);
        $this->assertSame(0, DB::table('discounts')->where('tenant_id', $tenant->id)->count());
    }

    public function test_uninstalling_never_touches_another_tenants_rows(): void
    {
        $a = $this->shopWithDiscount('SLEVA-A');
        $b = $this->shopWithDiscount('SLEVA-B');

        $registry = app(ModuleRegistry::class);
        $registry->deactivate($a, 'discounts');
        $registry->uninstall($a, 'discounts');

        // The delete is a hand-written WHERE — several purged tables have no
        // model to carry a scope — so this is the assertion that matters most.
        $this->assertSame(0, DB::table('discounts')->where('tenant_id', $a->id)->count());
        $this->assertSame(1, DB::table('discounts')->where('tenant_id', $b->id)->count());
    }

    public function test_orders_and_documents_survive_a_discount_uninstall(): void
    {
        $tenant = $this->shopWithDiscount('SLEVA10');
        $registry = app(ModuleRegistry::class);

        $registry->deactivate($tenant, 'discounts');
        $registry->uninstall($tenant, 'discounts');

        // order_discounts snapshots the name and amount instead of holding a
        // foreign key, which is exactly why discounts can be deleted at all.
        $this->assertTrue(\Schema::hasTable('order_discounts'));
        $this->assertSame(0, DB::table('order_discounts')->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_uninstall_is_audited_with_what_it_deleted(): void
    {
        $tenant = $this->shopWithDiscount('SLEVA10');
        $registry = app(ModuleRegistry::class);

        $registry->deactivate($tenant, 'discounts');
        $registry->uninstall($tenant, 'discounts');

        $entry = $this->context->runAs(
            $tenant,
            fn () => AuditLogEntry::where('action', 'module.uninstalled')->first(),
        );

        $this->assertNotNull($entry);
        $this->assertSame('discounts', $entry->meta['module']);
        $this->assertSame(1, $entry->meta['deleted']['discounts']);
    }

    public function test_the_orchestrator_exports_before_it_deletes(): void
    {
        $tenant = $this->shopWithDiscount('SLEVA10');
        $registry = app(ModuleRegistry::class);
        $registry->deactivate($tenant, 'discounts');

        $result = app(UninstallModule::class)->run($tenant, 'discounts');

        $archive = Storage::disk(FileStorage::PRIVATE_DISK)
            ->path('tenants/'.$tenant->id.'/'.$result['export']->path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive) === true);

        $rows = $zip->getFromName('data/discounts.json');
        $zip->close();

        // The row is gone from the database and present in the archive — the
        // merchant who uninstalled the wrong module has a way back.
        $this->assertStringContainsString('SLEVA10', (string) $rows);
        $this->assertSame(0, DB::table('discounts')->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_backup_covers_only_the_module_being_removed(): void
    {
        $tenant = $this->shopWithDiscount('SLEVA10');
        app(ModuleRegistry::class)->deactivate($tenant, 'discounts');

        $result = app(UninstallModule::class)->run($tenant, 'discounts');

        $this->assertSame(
            ['discount_redemptions', 'discount_targets', 'discounts'],
            array_keys($result['export']->rowCounts),
        );
    }

    public function test_an_unsupported_module_fails_without_writing_an_archive(): void
    {
        $tenant = Tenant::factory()->create();

        $before = count(Storage::disk(FileStorage::PRIVATE_DISK)->allFiles());

        try {
            app(UninstallModule::class)->run($tenant, 'orders');
            $this->fail('uninstalling orders should not be possible');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('does not support', $e->getMessage());
        }

        $this->assertSame($before, count(Storage::disk(FileStorage::PRIVATE_DISK)->allFiles()));
    }

    public function test_reactivating_after_an_uninstall_gives_an_empty_module(): void
    {
        $tenant = $this->shopWithDiscount('SLEVA10');
        $registry = app(ModuleRegistry::class);

        $registry->deactivate($tenant, 'discounts');
        $registry->uninstall($tenant, 'discounts');
        $registry->activate($tenant, 'discounts');

        // Deactivation is reversible and keeps the data; uninstall is not.
        // Switching the module back on must not resurrect anything.
        $this->assertSame(0, DB::table('discounts')->where('tenant_id', $tenant->id)->count());
        $this->assertTrue($registry->isEnabled($tenant, 'discounts'));
    }
}
