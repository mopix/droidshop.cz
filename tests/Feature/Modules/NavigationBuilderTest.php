<?php

namespace Tests\Feature\Modules;

use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\NavigationBuilder;
use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class NavigationBuilderTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private NavigationBuilder $navigation;

    private ModuleRegistry $registry;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->navigation = app(NavigationBuilder::class);
        $this->registry = app(ModuleRegistry::class);
        $this->tenant = Tenant::factory()->create();
    }

    private function moduleWithNav(string $key, string $label, ?int $order): Module
    {
        $nav = ['area' => 'admin', 'label' => $label, 'route' => "admin.{$key}.index"];

        if ($order !== null) {
            $nav['order'] = $order;
        }

        return Module::factory()->key($key)->create([
            'manifest' => [
                'name' => $key,
                'version' => '1.0.0',
                'requires' => [],
                'nav' => [$nav],
            ],
        ]);
    }

    public function test_entries_are_sorted_by_order(): void
    {
        $this->moduleWithNav('orders', 'Objednávky', 20);
        $this->moduleWithNav('products', 'Produkty', 10);

        $this->activateModule($this->tenant, 'orders');
        $this->activateModule($this->tenant, 'products');

        $labels = $this->navigation->forTenant($this->tenant)->pluck('label')->all();

        $this->assertSame(['Produkty', 'Objednávky'], $labels);
    }

    public function test_entries_without_order_sink_to_the_bottom(): void
    {
        // Rather than jumping to the top and shoving the core menu around.
        $this->moduleWithNav('misc', 'Ostatní', null);
        $this->moduleWithNav('products', 'Produkty', 10);

        $this->activateModule($this->tenant, 'misc');
        $this->activateModule($this->tenant, 'products');

        $labels = $this->navigation->forTenant($this->tenant)->pluck('label')->all();

        $this->assertSame(['Produkty', 'Ostatní'], $labels);
    }

    public function test_disabled_module_is_absent_from_the_menu(): void
    {
        $this->moduleWithNav('products', 'Produkty', 10);
        $this->moduleWithNav('blog', 'Blog', 20);

        $this->activateModule($this->tenant, 'products');

        $labels = $this->navigation->forTenant($this->tenant)->pluck('label')->all();

        $this->assertSame(['Produkty'], $labels);
    }

    public function test_killed_module_disappears_from_the_menu(): void
    {
        $module = $this->moduleWithNav('products', 'Produkty', 10);
        $this->activateModule($this->tenant, 'products');

        $module->update(['enabled_globally' => false]);
        $this->registry->flush();

        $this->assertCount(0, $this->navigation->forTenant($this->tenant));
    }

    public function test_storefront_entries_are_not_mixed_into_the_admin_menu(): void
    {
        Module::factory()->key('widget')->create([
            'manifest' => [
                'name' => 'widget',
                'version' => '1.0.0',
                'requires' => [],
                'nav' => [['area' => 'storefront', 'label' => 'Widget', 'route' => 'storefront.widget.index']],
            ],
        ]);

        $this->activateModule($this->tenant, 'widget');

        $this->assertCount(0, $this->navigation->forTenant($this->tenant, 'admin'));
        $this->assertCount(1, $this->navigation->forTenant($this->tenant, 'storefront'));
    }
}
