<?php

namespace Tests\Feature\Theme;

use App\Core\Theme\Exceptions\InvalidThemeManifest;
use App\Core\Theme\ThemeRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The registry of storefront themes (wave 4.1, task 1).
 *
 * Themes are read from disk, not from the database: they ship with the deploy
 * the same way modules do, and a tenant only ever stores the key of the one
 * they picked.
 */
class ThemeRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->path = storage_path('framework/testing/themes-'.uniqid());
        File::ensureDirectoryExists($this->path);
        config()->set('themes.path', $this->path);

        $this->writeTheme('base', [
            'name' => 'Základní',
            'description' => 'Dnešní vzhled.',
            'tokens' => ['container' => '1152px', 'radius' => '0.75rem'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeTheme(string $key, array $manifest): void
    {
        File::ensureDirectoryExists("{$this->path}/{$key}");
        File::put(
            "{$this->path}/{$key}/theme.json",
            json_encode(['key' => $key, ...$manifest], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        // The registry insists a declared override exists on disk, so every
        // fixture that declares one has to ship it.
        foreach ($manifest['overrides'] ?? [] as $view) {
            [$namespace, $name] = explode('::', $view);
            $file = "{$this->path}/{$key}/views/{$namespace}/".str_replace('.', '/', $name).'.blade.php';

            File::ensureDirectoryExists(dirname($file));
            File::put($file, '');
        }
    }

    private function registry(): ThemeRegistry
    {
        return app(ThemeRegistry::class);
    }

    public function test_a_theme_is_read_from_disk(): void
    {
        $theme = $this->registry()->find('base');

        $this->assertSame('base', $theme->key);
        $this->assertSame('Základní', $theme->name);
        $this->assertSame('1152px', $theme->tokens['container']);
    }

    public function test_every_deployed_theme_is_listed(): void
    {
        $this->writeTheme('editorial', ['name' => 'Editorial']);

        $this->assertSame(['base', 'editorial'], $this->registry()->all()->keys()->all());
    }

    public function test_an_unknown_key_falls_back_to_the_default_theme(): void
    {
        // A shop whose theme directory was removed between deploys must still
        // render. A 500 on every storefront page is a worse answer to a
        // missing directory than the plain default look.
        Log::spy();

        $theme = $this->registry()->find('theme-that-was-deleted');

        $this->assertSame('base', $theme->key);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_null_key_is_the_default_theme(): void
    {
        $this->assertSame('base', $this->registry()->find(null)->key);
    }

    public function test_a_manifest_naming_an_unknown_token_is_refused(): void
    {
        $this->writeTheme('broken', ['name' => 'Broken', 'tokens' => ['borderRadius' => '4px']]);

        $this->expectException(InvalidThemeManifest::class);

        $this->registry()->all();
    }

    public function test_a_manifest_overriding_a_view_outside_the_allowed_list_is_refused(): void
    {
        // Checkout has one implementation on purpose: price arithmetic and the
        // no-JavaScript path must not fork per look.
        $this->writeTheme('broken', ['name' => 'Broken', 'overrides' => ['checkout::cart']]);

        $this->expectException(InvalidThemeManifest::class);

        $this->registry()->all();
    }

    public function test_a_manifest_whose_key_does_not_match_its_directory_is_refused(): void
    {
        File::ensureDirectoryExists("{$this->path}/editorial");
        File::put("{$this->path}/editorial/theme.json", json_encode(['key' => 'retail', 'name' => 'X']));

        $this->expectException(InvalidThemeManifest::class);

        $this->registry()->all();
    }

    public function test_unreadable_json_is_refused(): void
    {
        File::ensureDirectoryExists("{$this->path}/broken");
        File::put("{$this->path}/broken/theme.json", '{ not json');

        $this->expectException(InvalidThemeManifest::class);

        $this->registry()->all();
    }

    public function test_the_listing_is_cached_until_it_is_flushed(): void
    {
        $this->registry()->all();

        $this->writeTheme('editorial', ['name' => 'Editorial']);

        $this->assertSame(['base'], $this->registry()->all()->keys()->all());

        $this->registry()->flush();

        $this->assertSame(['base', 'editorial'], $this->registry()->all()->keys()->all());
    }

    public function test_a_theme_declares_which_views_it_overrides(): void
    {
        $this->writeTheme('editorial', [
            'name' => 'Editorial',
            'overrides' => ['storefront::layouts.shop', 'products::storefront.show'],
        ]);

        $this->assertSame(
            ['storefront::layouts.shop', 'products::storefront.show'],
            $this->registry()->find('editorial')->overrides,
        );
    }
}
