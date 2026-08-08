<?php

namespace Tests\Feature\Shop;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\PageCacheKey;
use App\Core\Shop\ShopSettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\ShopSettings;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * A shop behind a password (wave 3.6).
 *
 * The security-carrying part of the wave: the interesting cases are the ways
 * the lock could turn out to be decorative — a page served from the cache
 * that was stored before the lock went on, or a webhook the lock swallowed.
 */
class ShopLockTest extends TestCase
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

        foreach (['storefront', 'products', 'categories', 'checkout'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop.'.config('tenancy.platform_domain').$path;
    }

    private function lockShop(string $password = 'tajne-heslo'): void
    {
        app(TenantContext::class)->set($this->tenant);
        app(ShopSettingsService::class)->update(['locked' => true, 'lock_password' => $password]);
        app(TenantContext::class)->forget();
    }

    private function publishProduct(): Product
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Kladivo',
            'sku' => 'KLADIVO',
            'price' => 1000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));
    }

    public function test_a_locked_shop_answers_with_the_form_not_the_catalogue(): void
    {
        $product = $this->publishProduct();

        $this->lockShop();

        $home = $this->get($this->url('/'));
        $home->assertStatus(403);
        $home->assertSee('Heslo');
        $home->assertDontSee('Kladivo');

        $this->get($this->url('/produkt/'.$product->slug))->assertStatus(403)->assertDontSee('Kladivo');
        $this->get($this->url('/kosik'))->assertStatus(403);
    }

    public function test_the_right_password_unlocks_and_the_wrong_one_does_not(): void
    {
        $this->lockShop();

        $this->post($this->url('/zamek'), ['password' => 'spatne'])
            ->assertSessionHasErrors('password');

        $this->get($this->url('/'))->assertStatus(403);

        $this->post($this->url('/zamek'), ['password' => 'tajne-heslo'])->assertRedirect('/');

        $this->get($this->url('/'))->assertOk();
    }

    /**
     * A one-page unlock would be no unlock at all.
     */
    public function test_the_unlock_survives_moving_to_another_page(): void
    {
        $product = $this->publishProduct();
        $this->lockShop();

        $this->post($this->url('/zamek'), ['password' => 'tajne-heslo']);

        $this->get($this->url('/produkt/'.$product->slug))->assertOk()->assertSee('Kladivo');
    }

    /**
     * The most important test in the wave. A page stored before the lock went
     * on must never be handed out after it — that would make the lock
     * decorative in exactly the case it matters.
     */
    public function test_a_page_cached_before_the_lock_is_not_served_after_it(): void
    {
        config()->set('pagecache.enabled', true);
        config()->set('cache.default', 'array');

        $product = $this->publishProduct();

        // Warm the cache while the shop is open, and prove it really warmed:
        // without this the test would still pass on a shop whose pages were
        // never cached at all, and would prove nothing about the cache.
        $this->get($this->url('/produkt/'.$product->slug))->assertOk()->assertSee('Kladivo');
        $this->assertTrue($this->isCached('/produkt/'.$product->slug));

        $this->lockShop();

        $response = $this->get($this->url('/produkt/'.$product->slug));
        $response->assertStatus(403);
        $response->assertDontSee('Kladivo');
    }

    private function isCached(string $path): bool
    {
        $key = app(PageCacheKey::class)->for(
            Request::create($this->url($path)),
            $this->tenant->fresh(),
            Dimension::list(['catalog', 'theme']),
        );

        return Cache::store()->has($key);
    }

    public function test_a_locked_shop_stores_nothing_in_the_page_cache(): void
    {
        config()->set('pagecache.enabled', true);

        $this->lockShop();

        $this->get($this->url('/'))->assertStatus(403);

        // Unlock, and the first page must be built fresh rather than served
        // from anything the locked request left behind.
        $this->post($this->url('/zamek'), ['password' => 'tajne-heslo']);

        $this->get($this->url('/'))->assertOk()->assertDontSee('Heslo');
    }

    /**
     * A locked shop that stops taking "this order is paid" loses the payment
     * silently, and the merchant hears about it from the customer.
     */
    public function test_a_payment_webhook_still_gets_through(): void
    {
        $this->activateModule($this->tenant, 'payments');

        // What the gateway callback answers on its own, before any lock.
        $open = $this->post($this->url('/platba/notifikace'), [])->getStatusCode();

        $this->lockShop();

        // The same answer, whatever it is. What must not happen is the lock
        // answering instead — that is a payment the shop never hears about.
        $this->post($this->url('/platba/notifikace'), [])->assertStatus($open);
        $this->assertNotSame(403, $open);
    }

    public function test_the_admin_stays_reachable(): void
    {
        $this->lockShop();

        $this->actingAs($this->owner)
            ->get($this->url('/admin/nastaveni/zobrazeni'))
            ->assertOk();
    }

    /**
     * Staff can already see everything through the admin; asking them for a
     * second password to look at what they just locked helps nobody.
     */
    public function test_signed_in_staff_still_see_the_shop(): void
    {
        $this->lockShop();

        $this->actingAs($this->owner)->get($this->url('/'))->assertOk();
    }

    public function test_a_locked_shop_is_noindex_whatever_the_seo_screen_says(): void
    {
        $this->lockShop();

        $this->get($this->url('/'))
            ->assertStatus(403)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
    }

    public function test_the_password_is_stored_hashed(): void
    {
        $this->lockShop('jine-heslo');

        $stored = ShopSettings::query()->where('tenant_id', $this->tenant->id)->value('lock_password');

        $this->assertNotSame('jine-heslo', $stored);
        $this->assertTrue(Hash::check('jine-heslo', $stored));
    }

    /**
     * A hand-typed password is guessable by definition; without a limit a
     * script walks a short one in minutes.
     */
    public function test_unlock_attempts_are_rate_limited(): void
    {
        $this->lockShop();

        for ($i = 0; $i < 10; $i++) {
            $this->post($this->url('/zamek'), ['password' => 'spatne'.$i]);
        }

        $response = $this->post($this->url('/zamek'), ['password' => 'tajne-heslo']);

        $response->assertSessionHasErrors('password');
        $this->get($this->url('/'))->assertStatus(403);
    }

    /**
     * Locking with no password anywhere would shut the merchant out of their
     * own storefront with no way back in from it.
     */
    public function test_the_lock_cannot_be_switched_on_without_a_password(): void
    {
        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/zobrazeni'), ['locked' => true])
            ->assertSessionHasErrors('lock_password');

        $this->get($this->url('/'))->assertOk();
    }

    /**
     * Keep-on-update, like every other stored credential: an empty field means
     * "leave it alone", not "clear it".
     */
    public function test_an_empty_password_field_leaves_the_stored_one_alone(): void
    {
        $this->lockShop();

        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/zobrazeni'), [
                'locked' => true,
                'lock_password' => '',
                'hide_empty_categories' => true,
            ])
            ->assertRedirect();

        $this->post($this->url('/zamek'), ['password' => 'tajne-heslo'])->assertRedirect('/');
    }

    /**
     * The flag is mirrored onto the tenant row so the hot path can read it
     * without a query. Drift between the two would mean a shop that says it
     * is locked on its settings screen and is wide open in the browser.
     */
    public function test_the_lock_flag_is_mirrored_onto_the_tenant_row(): void
    {
        $this->assertFalse($this->tenant->fresh()->storefront_locked);

        $this->lockShop();
        $this->assertTrue($this->tenant->fresh()->storefront_locked);

        app(TenantContext::class)->set($this->tenant);
        app(ShopSettingsService::class)->update(['locked' => false]);
        app(TenantContext::class)->forget();

        $this->assertFalse($this->tenant->fresh()->storefront_locked);
        $this->get($this->url('/'))->assertOk();
    }

    public function test_robots_txt_still_answers_so_a_crawler_is_told_to_go_away(): void
    {
        $this->lockShop();

        $this->get($this->url('/robots.txt'))->assertOk()->assertSee('Disallow: /');
    }

    /**
     * Shops on subdomains of the platform can share a session cookie, so the
     * unlock must not carry from one shop to the next.
     */
    public function test_unlocking_one_shop_does_not_unlock_another(): void
    {
        $this->lockShop();
        $this->post($this->url('/zamek'), ['password' => 'tajne-heslo']);

        $other = Tenant::factory()->create(['name' => 'Cizí obchod']);
        Domain::create([
            'tenant_id' => $other->id,
            'domain' => 'jiny.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
        $this->activateModule($other, 'storefront');

        app(TenantContext::class)->set($other);
        app(ShopSettingsService::class)->update(['locked' => true, 'lock_password' => 'druhe-heslo']);
        app(TenantContext::class)->forget();

        $this->get('http://jiny.'.config('tenancy.platform_domain').'/')->assertStatus(403);
    }
}
