<?php

namespace Tests\Feature\Shop;

use App\Core\Shop\ShopSettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\ShopSettings;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The store behind the four settings screens (wave 3.6).
 *
 * What is being pinned down here is mostly about absence: a shop that has
 * never opened these screens still has to render, and a shop that saves them
 * has to see the change now rather than when the page cache expires.
 */
class ShopSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ShopSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);
        $this->service = app(ShopSettingsService::class);
    }

    /**
     * Nothing saved yet is the state every shop starts in, and it is the one
     * a null would break: the storefront layout reads these on every request.
     */
    public function test_a_tenant_without_a_row_gets_the_defaults(): void
    {
        $settings = $this->service->forCurrentTenant();

        $this->assertFalse($settings->exists);
        $this->assertSame(ShopSettings::DEFAULT_TIMEZONE, $settings->timezone);
        $this->assertFalse($settings->noindex);
        $this->assertFalse($settings->hide_empty_categories);
        $this->assertTrue($settings->show_footer_contact);
        $this->assertFalse($settings->locked);
        $this->assertSame(ShopSettings::DEFAULT_EMPTY_SEARCH_TEXT, $settings->emptySearchText());
        $this->assertSame('Můj obchod', $settings->seoTitleOr('Můj obchod'));
    }

    public function test_a_second_write_updates_the_same_row(): void
    {
        $this->service->update(['tagline' => 'První']);
        $this->service->update(['tagline' => 'Druhý']);

        $this->assertSame(1, ShopSettings::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('Druhý', $this->service->forCurrentTenant()->tagline);
    }

    public function test_one_shops_settings_are_invisible_from_another(): void
    {
        $this->service->update(['tagline' => 'Obchod A']);

        $other = Tenant::factory()->create();
        app(TenantContext::class)->set($other);

        $settings = app(ShopSettingsService::class)->forCurrentTenant();

        $this->assertFalse($settings->exists);
        $this->assertNull($settings->tagline);
    }

    /**
     * Almost everything on these screens renders into cached HTML. Without
     * the bump a merchant changes the tagline and does not see it for ten
     * minutes — and concludes the feature is broken.
     */
    public function test_a_write_bumps_every_page_cache_generation(): void
    {
        // Read from the database, not from the instance the factory returned:
        // the counters come from column defaults, so the in-memory model has
        // them as null — and assertGreaterThan(null, 1) passes for any value,
        // which is exactly how this test first passed with the bump deleted.
        $this->tenant->refresh();

        $before = [
            $this->tenant->page_gen_catalog,
            $this->tenant->page_gen_content,
            $this->tenant->page_gen_theme,
        ];

        $this->service->update(['tagline' => 'Nový slogan']);

        $fresh = $this->tenant->fresh();

        $this->assertGreaterThan($before[0], $fresh->page_gen_catalog);
        $this->assertGreaterThan($before[1], $fresh->page_gen_content);
        $this->assertGreaterThan($before[2], $fresh->page_gen_theme);
    }

    /**
     * The lock is a security control, not a curtain. A plaintext column would
     * hand every shop's lock password to anyone who reads one backup.
     */
    public function test_the_lock_password_is_stored_hashed(): void
    {
        $this->service->update(['locked' => true, 'lock_password' => 'tajne-heslo']);

        $stored = ShopSettings::query()->where('tenant_id', $this->tenant->id)->value('lock_password');

        $this->assertNotSame('tajne-heslo', $stored);
        $this->assertTrue(Hash::check('tajne-heslo', $stored));
    }

    public function test_contact_lines_skip_what_was_not_filled_in(): void
    {
        $this->service->update([
            'contact_email' => 'info@obchod.cz',
            'contact_city' => 'Brno',
            'contact_zip' => '60200',
        ]);

        $settings = $this->service->forCurrentTenant();
        $labels = array_column($settings->contactLines(), 'label');

        $this->assertSame(['E-mail', 'Adresa'], $labels);
        $this->assertSame('60200 Brno', $settings->address());
        $this->assertSame([], $settings->socialLinks());
        $this->assertTrue($settings->hasContactDetails());
    }

    public function test_a_shop_with_nothing_filled_in_has_no_contact_box(): void
    {
        $this->assertFalse($this->service->forCurrentTenant()->hasContactDetails());
    }
}
