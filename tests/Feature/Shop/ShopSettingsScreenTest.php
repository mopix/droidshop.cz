<?php

namespace Tests\Feature\Shop;

use App\Models\Domain;
use App\Models\ShopSettings;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Obchod and Kontakty screens (wave 3.6).
 */
class ShopSettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Můj obchod']);
        Domain::create([
            'tenant_id' => $this->tenant->id,
            'domain' => 'shop.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').$path;
    }

    public function test_the_shop_screen_renders_for_the_owner(): void
    {
        $this->actingAs($this->owner)
            ->get($this->url('/admin/nastaveni/obchod'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Settings/Shop')
                ->where('shop.name', 'Můj obchod')
                ->where('shop.timezone', ShopSettings::DEFAULT_TIMEZONE));
    }

    public function test_the_contacts_screen_renders_for_the_owner(): void
    {
        $this->actingAs($this->owner)
            ->get($this->url('/admin/nastaveni/kontakty'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Tenant/Settings/Contacts'));
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->url('/admin/nastaveni/obchod'))->assertRedirect();
        $this->get($this->url('/admin/nastaveni/kontakty'))->assertRedirect();
    }

    /**
     * A signed-in user who is not a member of THIS shop. 403 rather than 404
     * is the existing convention of EnsureTenantMember (wave 2.4).
     */
    public function test_a_stranger_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->url('/admin/nastaveni/obchod'))
            ->assertForbidden();
    }

    public function test_the_shop_name_and_tagline_are_saved(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/obchod'), [
                'name' => 'Nářadí Novák',
                'tagline' => 'Nářadí, které vydrží',
                'timezone' => 'Europe/Prague',
                'date_format' => 'd.m.Y',
                'time_format' => 'H:i',
            ])
            ->assertRedirect();

        $this->assertSame('Nářadí Novák', $this->tenant->fresh()->name);
        $this->assertSame('Nářadí, které vydrží', ShopSettings::query()->value('tagline'));
        $this->assertSame('d.m.Y', ShopSettings::query()->value('date_format'));
    }

    /**
     * An identifier PHP does not know makes every date the shop renders throw,
     * and the first place that surfaces is an order detail, not this form.
     */
    public function test_an_unknown_timezone_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/obchod'), [
                'name' => 'Obchod',
                'timezone' => 'Middle/Earth',
                'date_format' => 'd.m.Y',
                'time_format' => 'H:i',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertDatabaseCount('shop_settings', 0);
    }

    public function test_an_unlisted_date_format_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/obchod'), [
                'name' => 'Obchod',
                'timezone' => 'Europe/Prague',
                'date_format' => '<script>alert(1)</script>',
                'time_format' => 'H:i',
            ])
            ->assertSessionHasErrors('date_format');
    }

    public function test_contacts_are_saved_and_the_country_is_upper_cased(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/kontakty'), [
                'contact_email' => 'info@obchod.cz',
                'contact_phone' => '+420 777 123 456',
                'contact_country' => 'cz',
                'facebook_url' => 'https://facebook.com/obchod',
            ])
            ->assertRedirect();

        $settings = ShopSettings::query()->first();

        $this->assertSame('info@obchod.cz', $settings->contact_email);
        $this->assertSame('CZ', $settings->contact_country);
        $this->assertSame('https://facebook.com/obchod', $settings->facebook_url);
    }

    /**
     * These land in an href on a public page. `url` on its own would accept a
     * javascript: scheme — the hole BlockUrl closes for homepage blocks.
     */
    public function test_a_javascript_link_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/kontakty'), [
                'facebook_url' => 'javascript:alert(document.cookie)',
            ])
            ->assertSessionHasErrors('facebook_url');

        $this->assertDatabaseCount('shop_settings', 0);
    }

    public function test_a_malformed_email_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/kontakty'), ['contact_email' => 'tohle-neni-email'])
            ->assertSessionHasErrors('contact_email');
    }

    public function test_saving_bumps_the_page_cache(): void
    {
        $this->tenant->refresh();
        $before = $this->tenant->page_gen_content;

        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/kontakty'), ['contact_email' => 'info@obchod.cz']);

        $this->assertGreaterThan($before, $this->tenant->fresh()->page_gen_content);
    }
}
