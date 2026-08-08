<?php

namespace Tests\Feature\Modules;

use App\Core\Modules\Exceptions\InvalidManifest;
use App\Core\Modules\ManifestValidator;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\NavigationBuilder;
use App\Core\Modules\NavigationGroup;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The admin menu is divided into sections, and the division has to survive
 * the thing the whole module system exists for: a shop switching modules on
 * and off. A section is a kernel concept; a manifest only says which one its
 * entry belongs to.
 */
class NavigationGroupTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->create();
    }

    private function menuGroups(): array
    {
        return app(NavigationBuilder::class)->groupedForTenant($this->tenant);
    }

    private function labelsIn(string $key): array
    {
        foreach ($this->menuGroups() as $group) {
            if ($group['key'] === $key) {
                return array_column($group['items'], 'label');
            }
        }

        return [];
    }

    public function test_catalogue_modules_land_in_the_products_section(): void
    {
        $this->activateModule($this->tenant, 'products');
        $this->activateModule($this->tenant, 'categories');

        $this->assertSame(
            ['Produkty', 'Import / export', 'Kategorie'],
            $this->labelsIn('products'),
        );
    }

    public function test_the_order_side_lands_in_the_orders_section(): void
    {
        foreach (['orders', 'docs', 'customers'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $labels = $this->labelsIn('orders');

        $this->assertContains('Objednávky', $labels);
        $this->assertContains('Doklady', $labels);
        $this->assertContains('Zákazníci', $labels);
    }

    /**
     * The kernel's order, not the order modules happen to sit on disk in —
     * otherwise the menu would rearrange itself every time a shop switched a
     * module on.
     */
    public function test_sections_come_back_in_the_kernels_order(): void
    {
        foreach (['products', 'orders', 'discounts', 'pages'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->assertSame(
            ['products', 'orders', 'modules', 'settings'],
            array_column($this->menuGroups(), 'key'),
        );
    }

    /**
     * A heading with nothing under it reads as something broken.
     *
     * `settings` is always there because `storefront` is a core module and
     * therefore runs in every shop — its Homepage entry lives in that
     * section. What must not appear is `orders` or `modules`, whose modules
     * this shop does not run.
     */
    public function test_an_empty_section_is_not_returned(): void
    {
        $this->activateModule($this->tenant, 'products');

        $keys = array_column($this->menuGroups(), 'key');

        $this->assertContains('products', $keys);
        $this->assertNotContains('orders', $keys);
        $this->assertNotContains('modules', $keys);
    }

    /**
     * A shop that runs nothing optional still has the core module's entry,
     * and nothing else.
     */
    public function test_a_shop_running_nothing_optional_keeps_only_the_core_entry(): void
    {
        $this->assertSame(['settings'], array_column($this->menuGroups(), 'key'));
        $this->assertSame(['Homepage'], $this->labelsIn('settings'));
    }

    /**
     * A deactivated module must leave no dangling link — the reason the menu
     * is built from the registry rather than written out by hand.
     */
    public function test_a_deactivated_module_disappears_from_the_menu(): void
    {
        $this->activateModule($this->tenant, 'products');
        $this->assertNotSame([], $this->labelsIn('products'));

        app(ModuleRegistry::class)->deactivate($this->tenant, 'products');

        // Only this module's own entries go. `categories` came along as a
        // dependency and stays switched on — deactivating a module does not
        // deactivate what it depended on, and its own menu entry is still
        // reachable.
        $labels = $this->labelsIn('products');

        $this->assertNotContains('Produkty', $labels);
        $this->assertNotContains('Import / export', $labels);
    }

    /**
     * An entry that quietly vanished would be a feature the tenant is paying
     * for and cannot reach, so a missing group files it visibly instead.
     */
    public function test_an_entry_without_a_group_is_filed_rather_than_dropped(): void
    {
        $entries = app(NavigationBuilder::class)->forTenant($this->tenant);

        foreach ($entries as $entry) {
            $this->assertNotNull(NavigationGroup::tryFrom($entry['group']));
        }

        $this->assertSame('modules', NavigationGroup::fallback()->value);
    }

    /**
     * Caught at deploy, not at request time: a typo would otherwise file the
     * entry in the fallback section, where it looks filed rather than
     * misfiled — and nobody hunts for a menu item that is visible, just in
     * the wrong place.
     */
    public function test_an_unknown_group_is_refused_at_sync_time(): void
    {
        $this->expectException(InvalidManifest::class);

        app(ManifestValidator::class)->validate([
            'name' => 'broken',
            'version' => '1.0.0',
            'title' => ['cs' => 'Rozbitý'],
            'level' => 'base',
            'nav' => [[
                'area' => 'admin',
                'label' => 'Něco',
                'route' => 'admin.broken.index',
                'group' => 'neexistujici',
            ]],
        ]);
    }

    /**
     * Every shipped manifest has to name a section, or the menu the owner
     * asked for silently grows a catch-all.
     */
    public function test_every_shipped_admin_entry_declares_a_group(): void
    {
        foreach (glob(base_path('Modules/*/module.json')) as $path) {
            $manifest = json_decode((string) file_get_contents($path), true);

            foreach ($manifest['nav'] ?? [] as $entry) {
                if (($entry['area'] ?? 'admin') !== 'admin') {
                    continue;
                }

                $this->assertArrayHasKey(
                    'group',
                    $entry,
                    "[{$manifest['name']}] menu entry [{$entry['route']}] declares no group",
                );
            }
        }
    }
}
