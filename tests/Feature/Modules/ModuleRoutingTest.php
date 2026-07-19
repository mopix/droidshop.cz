<?php

namespace Tests\Feature\Modules;

use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pages\Models\Page;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * End-to-end proof that a module is reachable exactly for the tenants that
 * run it — the point of the whole wave.
 */
class ModuleRoutingTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private ModuleRegistry $registry;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->registry = app(ModuleRegistry::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenantA = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->tenantB = Tenant::factory()->withDomain('shop2.droidshop')->create();
    }

    private function publishPageFor(Tenant $tenant, string $slug = 'kontakt'): void
    {
        $this->context->runAs($tenant, fn () => Page::query()->updateOrCreate(
            ['slug' => $slug],
            ['title' => 'Kontakt', 'body' => 'Telefon: 123', 'is_published' => true],
        ));
    }

    public function test_storefront_page_answers_for_a_tenant_running_the_module(): void
    {
        $this->activateModule($this->tenantA, 'pages');
        $this->publishPageFor($this->tenantA);

        $this->get('http://shop1.droidshop/stranka/kontakt')
            ->assertOk()
            ->assertSee('Kontakt')
            ->assertSee('Telefon: 123');
    }

    public function test_same_url_is_404_for_a_tenant_without_the_module(): void
    {
        $this->activateModule($this->tenantA, 'pages');
        $this->publishPageFor($this->tenantA);

        $this->get('http://shop2.droidshop/stranka/kontakt')->assertNotFound();
    }

    public function test_tenant_with_the_module_cannot_see_another_tenants_page(): void
    {
        // Both run the module; the data still must not cross.
        $this->activateModule($this->tenantA, 'pages');
        $this->activateModule($this->tenantB, 'pages');
        $this->publishPageFor($this->tenantA);

        $this->get('http://shop2.droidshop/stranka/kontakt')->assertNotFound();
    }

    public function test_module_routes_do_not_exist_on_the_platform_host(): void
    {
        $this->activateModule($this->tenantA, 'pages');

        $this->get('http://droidshop/stranka/kontakt')->assertNotFound();
    }

    public function test_unpublished_page_is_not_served(): void
    {
        $this->activateModule($this->tenantA, 'pages');

        $this->context->runAs($this->tenantA, fn () => Page::query()->create([
            'slug' => 'draft', 'title' => 'Draft', 'is_published' => false,
        ]));

        $this->get('http://shop1.droidshop/stranka/draft')->assertNotFound();
    }

    public function test_kill_switch_takes_the_module_away_without_a_redeploy(): void
    {
        $this->activateModule($this->tenantA, 'pages');
        $this->publishPageFor($this->tenantA);
        $this->get('http://shop1.droidshop/stranka/kontakt')->assertOk();

        Module::query()->where('key', 'pages')->update(['enabled_globally' => false]);
        $this->registry->flush();

        $this->get('http://shop1.droidshop/stranka/kontakt')->assertNotFound();
    }

    public function test_deactivation_hides_the_page_but_keeps_it(): void
    {
        $this->activateModule($this->tenantA, 'pages');
        $this->publishPageFor($this->tenantA);

        $this->registry->deactivate($this->tenantA, 'pages');
        $this->get('http://shop1.droidshop/stranka/kontakt')->assertNotFound();

        $this->activateModule($this->tenantA, 'pages');
        $this->get('http://shop1.droidshop/stranka/kontakt')->assertOk()->assertSee('Telefon: 123');
    }

    public function test_admin_route_is_mounted_under_the_module_prefix(): void
    {
        $this->activateModule($this->tenantA, 'pages');

        $this->get('http://shop1.droidshop/admin/m/pages')
            ->assertOk()
            ->assertJsonStructure(['pages']);
    }

    public function test_admin_route_is_404_for_a_tenant_without_the_module(): void
    {
        $this->get('http://shop2.droidshop/admin/m/pages')->assertNotFound();
    }

    public function test_activation_seeds_the_legally_required_pages(): void
    {
        $this->activateModule($this->tenantA, 'pages');

        $slugs = $this->context->runAs($this->tenantA, fn () => Page::query()->pluck('slug')->all());

        $this->assertEqualsCanonicalizing(
            ['obchodni-podminky', 'ochrana-osobnich-udaju', 'kontakt'],
            $slugs
        );
    }

    public function test_reactivation_does_not_duplicate_seeded_pages(): void
    {
        $this->activateModule($this->tenantA, 'pages');
        $this->registry->deactivate($this->tenantA, 'pages');
        $this->activateModule($this->tenantA, 'pages');

        $count = $this->context->runAs($this->tenantA, fn () => Page::query()->count());

        $this->assertSame(3, $count);
    }

    public function test_seeded_pages_belong_to_the_activating_tenant_only(): void
    {
        $this->activateModule($this->tenantA, 'pages');

        $countForB = $this->context->runAs($this->tenantB, fn () => Page::query()->count());

        $this->assertSame(0, $countForB);
    }

    public function test_page_renders_seo_tags_server_side(): void
    {
        // Binding storefront rule: the full page has to be in the server's
        // first response, meta tags included.
        $this->activateModule($this->tenantA, 'pages');

        $this->context->runAs($this->tenantA, fn () => Page::query()->updateOrCreate(
            ['slug' => 'o-nas'],
            [
                'title' => 'O nás',
                'body' => 'Text',
                'is_published' => true,
                'seo_title' => 'O nás | Shop One',
                'seo_description' => 'Kdo jsme.',
            ],
        ));

        $this->get('http://shop1.droidshop/stranka/o-nas')
            ->assertOk()
            ->assertSee('<title>O nás | Shop One</title>', false)
            ->assertSee('<meta name="description" content="Kdo jsme.">', false)
            ->assertSee('rel="canonical"', false);
    }
}
