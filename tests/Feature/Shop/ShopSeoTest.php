<?php

namespace Tests\Feature\Shop;

use App\Core\Shop\ShopSettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * What the shop tells a search engine (wave 3.6).
 */
class ShopSeoTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');

        $this->artisan('modules:sync')->assertSuccessful();

        app(TenantContext::class)->forget();

        $this->tenant = Tenant::factory()->create(['name' => 'Nářadí Novák']);
        Domain::create([
            'tenant_id' => $this->tenant->id,
            'domain' => 'shop.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->activateModule($this->tenant, 'storefront');
    }

    private function url(string $path): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').$path;
    }

    private function save(array $data): void
    {
        app(TenantContext::class)->set($this->tenant);
        app(ShopSettingsService::class)->update($data);
        app(TenantContext::class)->forget();
    }

    public function test_the_screen_renders(): void
    {
        $this->actingAs($this->owner)
            ->get($this->url('/admin/nastaveni/seo'))
            ->assertOk();
    }

    public function test_a_custom_title_and_description_reach_the_homepage(): void
    {
        $this->save([
            'seo_title' => 'Nářadí Novák — profesionální nářadí',
            'seo_description' => 'Vrtačky, brusky a nářadí, které vydrží.',
        ]);

        $response = $this->get($this->url('/'))->assertOk();

        $response->assertSee('<title>Nářadí Novák — profesionální nářadí</title>', escape: false);
        $response->assertSee('Vrtačky, brusky a nářadí, které vydrží.');
    }

    /**
     * Empty must fall back to what the page always did, not to an empty
     * <title> — which is worse than the derived one it replaced.
     */
    public function test_an_empty_title_degrades_to_the_shop_name(): void
    {
        $this->get($this->url('/'))
            ->assertOk()
            ->assertSee('<title>Nářadí Novák</title>', escape: false);
    }

    public function test_noindex_reaches_both_the_meta_tag_and_robots_txt(): void
    {
        $this->save(['noindex' => true]);

        $this->get($this->url('/'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);

        $robots = $this->get($this->url('/robots.txt'))->assertOk();

        $robots->assertSee('Disallow: /');
        $robots->assertDontSee('Sitemap:');
    }

    public function test_an_indexable_shop_still_says_index(): void
    {
        $this->get($this->url('/'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="index, follow">', escape: false);

        $this->get($this->url('/robots.txt'))->assertOk()->assertSee('Sitemap:');
    }

    public function test_an_uploaded_image_becomes_the_og_image(): void
    {
        Storage::fake('tenant_public');

        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/seo'), [
                'og_image' => UploadedFile::fake()->image('sdileni.png', 1200, 630),
            ])
            ->assertRedirect();

        $this->get($this->url('/'))->assertOk()->assertSee('og:image', escape: false);
    }

    /** Guards the test above: without an upload there is no og:image at all. */
    public function test_a_shop_without_an_image_emits_no_og_image(): void
    {
        $this->get($this->url('/'))->assertOk()->assertDontSee('og:image', escape: false);
    }

    /**
     * An SVG is active content: it can carry a <script> that runs on its own
     * URL, which is stored XSS a merchant could reach. Raster only, same rule
     * as favicons (wave 2.2) and product images.
     */
    public function test_an_svg_is_refused(): void
    {
        Storage::fake('tenant_public');

        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/seo'), [
                'og_image' => UploadedFile::fake()->create('utok.svg', 4, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('og_image');
    }

    public function test_saving_bumps_the_page_cache(): void
    {
        $this->tenant->refresh();
        $before = $this->tenant->page_gen_content;

        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/seo'), ['seo_title' => 'Nový titulek']);

        $this->assertGreaterThan($before, $this->tenant->fresh()->page_gen_content);
    }
}
