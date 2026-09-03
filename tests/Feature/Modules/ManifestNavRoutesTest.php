<?php

namespace Tests\Feature\Modules;

use App\Core\Modules\Manifest;
use App\Core\Modules\ModuleRegistry;
use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Every nav entry a manifest declares has to name a route that exists.
 *
 * Ziggy resolves these names in the browser and throws on one it does not
 * know, so a manifest naming a route that was never registered does not break
 * the module's own screen — it breaks every admin page of every tenant that
 * runs the module, and for a `level: base` module that is all of them. No
 * other test renders the navigation, so nothing else would catch it.
 *
 * The companion of NavIconCoverageTest: that one guards the icon, this one
 * the target.
 */
class ManifestNavRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_manifest_nav_entry_points_at_a_route_that_exists(): void
    {
        $this->artisan('modules:sync')->assertSuccessful();

        $this->assertNavRoutesExist();
    }

    /**
     * Deliberately not part of the test method above: the check has to be
     * runnable without re-running `modules:sync`, which reads the manifests
     * back off disk and would undo the broken row the second test writes.
     */
    private function assertNavRoutesExist(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutesByName())->keys();

        foreach (app(ModuleRegistry::class)->all() as $module) {
            $manifest = Manifest::fromArray($module->manifest);

            foreach ($manifest->nav as $entry) {
                $this->assertTrue(
                    $names->contains($entry['route']),
                    "Modul {$module->key} má v nav routu {$entry['route']}, která neexistuje.",
                );
            }
        }
    }

    /**
     * The guard above is only worth having if it fails on a bad manifest, and
     * a manifest is a file on disk — so the broken one is built here in the
     * database instead, which is where the registry reads from anyway.
     */
    public function test_the_guard_fails_on_a_nav_entry_pointing_nowhere(): void
    {
        $this->artisan('modules:sync')->assertSuccessful();

        $module = Module::query()->firstOrFail();
        $manifest = $module->manifest;
        $manifest['nav'] = [[
            'area' => 'admin',
            'label' => 'Nikam',
            'route' => 'admin.reviews.neexistuje',
            'icon' => 'star',
            'order' => 1,
            'group' => 'orders',
        ]];
        $module->update(['manifest' => $manifest]);

        app(ModuleRegistry::class)->flush();

        $this->expectException(AssertionFailedError::class);

        $this->assertNavRoutesExist();
    }
}
