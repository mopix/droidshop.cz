<?php

namespace Tests\Feature\Theme;

use App\Core\Tenancy\TenantContext;
use App\Core\Theme\ThemeRegistry;
use App\Core\Theme\ThemeResolver;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Design tokens of the chosen theme, and where the tenant's own brand still
 * outranks them (wave 4.1, task 3).
 */
class ThemeTokenTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->path = storage_path('framework/testing/themes-'.uniqid());
        config()->set('themes.path', $this->path);

        $this->writeTheme('base', ['container' => '72rem', 'radius' => '0.75rem']);
        app(ThemeRegistry::class)->flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function writeTheme(string $key, array $tokens): void
    {
        File::ensureDirectoryExists("{$this->path}/{$key}");
        File::put("{$this->path}/{$key}/theme.json", json_encode([
            'key' => $key,
            'name' => ucfirst($key),
            'tokens' => $tokens,
        ]));
    }

    private function shop(string $template): Tenant
    {
        $this->artisan('modules:sync')->assertSuccessful();

        $tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($tenant, 'storefront');

        TenantTheme::create([
            'tenant_id' => $tenant->id,
            'template' => $template,
            'primary_color' => '#ff0000',
            'accent_color' => '#00ff00',
        ]);

        app(TenantContext::class)->forget();

        return $tenant;
    }

    public function test_the_page_carries_the_themes_tokens(): void
    {
        $this->writeTheme('editorial', ['container' => '80rem', 'radius' => '0', 'card' => 'plain']);
        app(ThemeRegistry::class)->flush();

        $this->shop('editorial');

        $response = $this->get('http://obchod.droidshop/');

        $response->assertSee('--container: 80rem', false);
        $response->assertSee('--radius: 0', false);
        $response->assertSee('--card: plain', false);
    }

    public function test_the_tenants_brand_colour_outranks_the_theme(): void
    {
        // A merchant with a red logo must not end up with an orange shop
        // because they liked a theme's layout.
        $this->writeTheme('editorial', ['ink' => '#111111']);
        app(ThemeRegistry::class)->flush();

        $this->shop('editorial');

        $response = $this->get('http://obchod.droidshop/');

        $response->assertSee('--brand-primary: #ff0000', false);
        $response->assertSee('--brand-accent: #00ff00', false);
    }

    public function test_a_token_carrying_css_syntax_never_reaches_the_page(): void
    {
        // Blade's {{ }} escapes HTML, not CSS: a value like this would close
        // the custom-property declaration and inject rules into every cached
        // page of the shop.
        $this->writeTheme('nasty', ['surface' => 'red; } body { display: none } .x {']);
        app(ThemeRegistry::class)->flush();

        $this->shop('nasty');

        $response = $this->get('http://obchod.droidshop/');

        // Not a bare "display: none": the layout's own inline rule for the
        // cookie banner contains that string on every page.
        $response->assertDontSee('body { display: none }', false);
        $response->assertDontSee('--surface: red', false);
    }

    public function test_a_shop_that_never_chose_a_theme_gets_the_default(): void
    {
        $this->artisan('modules:sync')->assertSuccessful();

        $tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($tenant, 'storefront');
        app(TenantContext::class)->forget();

        $this->get('http://obchod.droidshop/')->assertSee('--container: 72rem', false);
    }

    public function test_the_resolver_reports_which_theme_is_in_use(): void
    {
        $this->writeTheme('editorial', []);
        app(ThemeRegistry::class)->flush();

        $tenant = $this->shop('editorial');

        $theme = app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(ThemeResolver::class)->forCurrentTenant(),
        );

        $this->assertSame('editorial', $theme->key);
    }
}
