<?php

namespace Tests\Feature\Theme;

use App\Core\Tenancy\TenantContext;
use App\Core\Theme\Exceptions\InvalidThemeManifest;
use App\Core\Theme\ThemeRegistry;
use App\Core\Theme\ThemeViewPaths;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * How a theme replaces a view (wave 4.1, task 2).
 *
 * A theme puts a file of the same name in front of the base one, so the view
 * name never changes and view composers, @include and existing tests carry on
 * untouched.
 */
class ThemeViewResolutionTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->path = storage_path('framework/testing/themes-'.uniqid());
        config()->set('themes.path', $this->path);

        $this->writeTheme('base', []);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    /**
     * @param  list<string>  $overrides
     */
    private function writeTheme(string $key, array $overrides): void
    {
        File::ensureDirectoryExists("{$this->path}/{$key}");
        File::put("{$this->path}/{$key}/theme.json", json_encode([
            'key' => $key,
            'name' => ucfirst($key),
            'overrides' => $overrides,
        ]));

        foreach ($overrides as $view) {
            [$namespace, $name] = explode('::', $view);
            $file = "{$this->path}/{$key}/views/{$namespace}/".str_replace('.', '/', $name).'.blade.php';

            File::ensureDirectoryExists(dirname($file));
            File::put($file, "{{-- {$key} --}}");
        }
    }

    private function apply(string $key): void
    {
        app(ThemeViewPaths::class)->apply(app(ThemeRegistry::class)->find($key));
    }

    public function test_an_overridden_view_resolves_to_the_theme(): void
    {
        $this->writeTheme('editorial', ['storefront::components.product-card']);
        app(ThemeRegistry::class)->flush();

        $this->apply('editorial');

        $this->assertStringStartsWith(
            $this->path,
            View::getFinder()->find('storefront::components.product-card'),
        );
    }

    public function test_a_view_the_theme_does_not_override_still_comes_from_the_module(): void
    {
        $this->writeTheme('editorial', ['storefront::components.product-card']);
        app(ThemeRegistry::class)->flush();

        $this->apply('editorial');

        $this->assertStringContainsString(
            'Modules/Storefront',
            View::getFinder()->find('storefront::layouts.shop'),
        );
    }

    public function test_a_second_tenants_theme_replaces_the_first_ones_paths(): void
    {
        // The finder is a singleton. Code that only prepends would pass an
        // assertion on the rendered output whenever the newest theme happens
        // to sort first, and would still be leaking the previous tenant's
        // paths — so this asserts on the hints themselves, not on a render.
        $this->writeTheme('editorial', ['storefront::components.product-card']);
        $this->writeTheme('retail', ['storefront::components.product-card']);
        app(ThemeRegistry::class)->flush();

        $this->apply('editorial');
        $this->apply('retail');

        $hints = View::getFinder()->getHints()['storefront'];

        $this->assertContains("{$this->path}/retail/views/storefront", $hints);
        $this->assertNotContains("{$this->path}/editorial/views/storefront", $hints);
    }

    public function test_a_theme_without_overrides_restores_the_base_paths(): void
    {
        $this->writeTheme('editorial', ['storefront::components.product-card']);
        app(ThemeRegistry::class)->flush();

        $before = View::getFinder()->getHints();

        $this->apply('editorial');
        $this->apply('base');

        $this->assertSame($before, View::getFinder()->getHints());
    }

    public function test_a_theme_carrying_a_view_it_never_declared_is_refused(): void
    {
        // Otherwise a file dropped into themes/{key}/views/checkout/ would win
        // over the real checkout without appearing in the manifest at all —
        // the whitelist would guard the declaration and nothing would guard
        // the directory.
        $this->writeTheme('sneaky', []);
        File::ensureDirectoryExists("{$this->path}/sneaky/views/checkout");
        File::put("{$this->path}/sneaky/views/checkout/cart.blade.php", 'x');

        app(ThemeRegistry::class)->flush();

        $this->expectException(InvalidThemeManifest::class);

        app(ThemeRegistry::class)->all();
    }

    public function test_a_declared_override_without_a_file_is_refused(): void
    {
        File::ensureDirectoryExists("{$this->path}/lying");
        File::put("{$this->path}/lying/theme.json", json_encode([
            'key' => 'lying',
            'name' => 'Lying',
            'overrides' => ['storefront::home'],
        ]));

        app(ThemeRegistry::class)->flush();

        $this->expectException(InvalidThemeManifest::class);

        app(ThemeRegistry::class)->all();
    }

    public function test_two_tenants_served_in_one_process_each_get_their_own_theme(): void
    {
        // The whole point of replacing rather than prepending, exercised the
        // way it actually happens in production: two requests, one process.
        config()->set('tenancy.platform_domain', 'droidshop');
        $this->artisan('modules:sync')->assertSuccessful();

        $this->writeTheme('editorial', ['storefront::home']);
        $this->writeTheme('retail', ['storefront::home']);
        File::put("{$this->path}/editorial/views/storefront/home.blade.php", 'EDITORIAL HOME');
        File::put("{$this->path}/retail/views/storefront/home.blade.php", 'RETAIL HOME');
        app(ThemeRegistry::class)->flush();

        $a = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $b = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->activateModule($a, 'storefront');
        $this->activateModule($b, 'storefront');

        TenantTheme::create(['tenant_id' => $a->id, 'template' => 'editorial']);
        TenantTheme::create(['tenant_id' => $b->id, 'template' => 'retail']);

        app(TenantContext::class)->forget();

        $this->get('http://shop1.droidshop/')->assertSee('EDITORIAL HOME');
        $this->get('http://shop2.droidshop/')->assertSee('RETAIL HOME');
        $this->get('http://shop1.droidshop/')->assertSee('EDITORIAL HOME');
    }
}
