<?php

namespace Tests\Feature\Theme;

use App\Core\PageCache\Dimension;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Models\TenantTheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Picking a storefront theme in the tenant's admin (wave 4.1, task 4).
 *
 * The screen is behind `tenant.member` and the theme is part of the base
 * plan, so no new permission is involved — every merchant who can already
 * change their colours can change their layout.
 */
class ThemeSelectionTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->path = storage_path('framework/testing/themes-'.uniqid());
        config()->set('themes.path', $this->path);
        $this->writeTheme('base', 'Základní');
        $this->writeTheme('editorial', 'Editorial');
        app(ThemeRegistry::class)->flush();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    private function writeTheme(string $key, string $name): void
    {
        File::ensureDirectoryExists("{$this->path}/{$key}");
        File::put("{$this->path}/{$key}/theme.json", json_encode([
            'key' => $key,
            'name' => $name,
            'description' => "Popis {$key}.",
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): array
    {
        return [
            'primary_color' => '#0f172a',
            'accent_color' => '#2563eb',
            'template' => 'editorial',
            ...$overrides,
        ];
    }

    public function test_the_owner_picks_a_theme(): void
    {
        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/nastaveni/vzhled', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_theme', [
            'tenant_id' => $this->tenant->id,
            'template' => 'editorial',
        ]);
    }

    public function test_an_unknown_theme_is_refused(): void
    {
        TenantTheme::create(['tenant_id' => $this->tenant->id, 'template' => 'base']);

        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/nastaveni/vzhled', $this->payload(['template' => '../../etc']))
            ->assertSessionHasErrors('template');

        $this->assertDatabaseHas('tenant_theme', [
            'tenant_id' => $this->tenant->id,
            'template' => 'base',
        ]);
    }

    public function test_switching_theme_invalidates_the_page_cache(): void
    {
        $before = $this->tenant->fresh()->{Dimension::Theme->column()};

        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/nastaveni/vzhled', $this->payload());

        $this->assertNotSame($before, $this->tenant->fresh()->{Dimension::Theme->column()});
    }

    public function test_the_screen_offers_every_deployed_theme(): void
    {
        $this->actingAs($this->owner)
            ->get('http://shop1.droidshop/admin/nastaveni/vzhled')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tenant/Appearance')
                ->where('appearance.template', 'base')
                ->has('themes', 2)
                ->where('themes.1.key', 'editorial')
                ->where('themes.1.name', 'Editorial')
            );
    }

    public function test_a_tenant_cannot_write_another_tenants_theme(): void
    {
        // The screen reads the tenant from the request's host, never from the
        // payload, so there is no id to swap. Pinned by a test so it stays
        // that way.
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        TenantTheme::create(['tenant_id' => $other->id, 'template' => 'base']);

        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/nastaveni/vzhled', $this->payload(['tenant_id' => $other->id]));

        $this->assertDatabaseHas('tenant_theme', [
            'tenant_id' => $other->id,
            'template' => 'base',
        ]);
    }
}
