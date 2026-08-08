<?php

namespace Tests\Feature\Shop;

use App\Core\Shop\ShopSettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * What the settings actually change on a public page (wave 3.6).
 *
 * Blade SSR, so every assertion here is against the raw HTML of the first
 * response — nothing is allowed to arrive by fetch afterwards.
 */
class ShopSettingsStorefrontTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

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

        $this->activateModule($this->tenant, 'storefront');
    }

    private function save(array $data): void
    {
        app(TenantContext::class)->set($this->tenant);
        app(ShopSettingsService::class)->update($data);
        app(TenantContext::class)->forget();
    }

    private function home(): TestResponse
    {
        return $this->get('http://shop.'.config('tenancy.platform_domain').'/');
    }

    public function test_the_tagline_shows_in_the_header(): void
    {
        $this->save(['tagline' => 'Nářadí, které vydrží']);

        $this->home()->assertOk()->assertSee('Nářadí, které vydrží');
    }

    public function test_a_shop_without_a_tagline_renders_fine(): void
    {
        $this->home()->assertOk()->assertSee('Nářadí Novák');
    }

    public function test_filled_contacts_show_in_the_footer(): void
    {
        $this->save([
            'contact_email' => 'info@naradinovak.cz',
            'facebook_url' => 'https://facebook.com/naradinovak',
        ]);

        $response = $this->home()->assertOk();

        $response->assertSee('info@naradinovak.cz');
        $response->assertSee('https://facebook.com/naradinovak', escape: false);
        $response->assertSee('Kontakt');
    }

    /**
     * An empty "Kontakt" heading reads as a shop that lost its own phone
     * number — worse than no box at all.
     */
    public function test_an_empty_contact_box_is_not_rendered(): void
    {
        $this->home()->assertOk()->assertDontSee('Sledujte nás');
    }

    public function test_the_footer_box_can_be_switched_off(): void
    {
        $this->save(['contact_email' => 'info@naradinovak.cz', 'show_footer_contact' => false]);

        $this->home()->assertOk()->assertDontSee('info@naradinovak.cz');
    }
}
