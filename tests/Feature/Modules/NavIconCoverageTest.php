<?php

namespace Tests\Feature\Modules;

use Tests\TestCase;

/**
 * Every icon a manifest asks for has to exist in NavIcon's map.
 *
 * Without this, adding a module with an icon nobody registered gives it the
 * fallback mark — which looks like a deliberate choice rather than an
 * oversight, and nobody notices until a tenant asks why one menu row has a
 * circle in it.
 *
 * Reading the Vue file as text is crude, but the alternative is a JS test
 * runner for one assertion, and the project has no unit-level JS suite.
 */
class NavIconCoverageTest extends TestCase
{
    public function test_every_manifest_icon_is_registered(): void
    {
        $component = (string) file_get_contents(resource_path('js/Components/Ui/NavIcon.vue'));

        foreach (glob(base_path('Modules/*/module.json')) as $path) {
            $manifest = json_decode((string) file_get_contents($path), true);

            foreach ($manifest['nav'] ?? [] as $entry) {
                $icon = $entry['icon'] ?? null;

                if ($icon === null) {
                    continue;
                }

                // Quoted or bare, depending on whether the name has a dash.
                $this->assertMatchesRegularExpression(
                    "/(^|\s)'?".preg_quote($icon, '/')."'?:\s/m",
                    $component,
                    "[{$manifest['name']}] asks for icon [{$icon}], which NavIcon does not know",
                );
            }
        }
    }
}
