<?php

namespace Tests\Feature\Modules\Pages;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pages\Models\Page;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The pages screen was read-only until wave 3.2, which meant the three legal
 * pages every shop is seeded with could never be filled in or published: the
 * tenant had templates and no way to use them.
 */
class PageAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');
        $this->activateModule($this->tenant, 'pages');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://obchod.droidshop/admin/m/pages'.$path;
    }

    private function page(string $slug): Page
    {
        return $this->context->runAs($this->tenant, fn () => Page::query()->where('slug', $slug)->firstOrFail());
    }

    public function test_the_owner_can_publish_a_seeded_page(): void
    {
        $page = $this->page('kontakt');

        $this->actingAs($this->owner)
            ->put($this->url('/'.$page->id), [
                'title' => 'Kontakt',
                'slug' => 'kontakt',
                'body' => '<p>Ostrava, telefon 123.</p>',
                'is_published' => true,
            ])
            ->assertRedirect($this->url());

        $fresh = $this->page('kontakt');

        $this->assertTrue($fresh->is_published);
        $this->assertStringContainsString('telefon 123', (string) $fresh->body);

        $this->get('http://obchod.droidshop/kontakt')
            ->assertOk()
            ->assertSee('telefon 123');
    }

    public function test_creating_a_page_works(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), [
                'title' => 'Doprava a platba',
                'slug' => 'doprava-a-platba',
                'body' => '<p>Posíláme Zásilkovnou.</p>',
                'is_published' => true,
            ])
            ->assertRedirect($this->url());

        $this->get('http://obchod.droidshop/doprava-a-platba')
            ->assertOk()
            ->assertSee('Posíláme Zásilkovnou.');
    }

    /**
     * Sanitised on write, never on render: a tenant pasting markup from Word
     * must not be able to put a script tag on their own storefront.
     */
    public function test_markup_outside_the_allowlist_is_stripped_on_save(): void
    {
        $page = $this->page('kontakt');

        $this->actingAs($this->owner)->put($this->url('/'.$page->id), [
            'title' => 'Kontakt',
            'slug' => 'kontakt',
            'body' => '<p>Text</p><script>alert(1)</script><div onclick="x()">Blok</div>',
            'is_published' => true,
        ])->assertRedirect();

        $body = (string) $this->page('kontakt')->body;

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringContainsString('Text', $body);
    }

    /**
     * A page can be linked from every invoice e-mail the shop ever sent, so
     * renaming it has to leave a 301 behind (spec §15.3).
     */
    public function test_renaming_a_page_leaves_a_redirect(): void
    {
        $page = $this->page('kontakt');

        $this->actingAs($this->owner)->put($this->url('/'.$page->id), [
            'title' => 'Kontakty',
            'slug' => 'kontakty',
            'body' => '<p>Text</p>',
            'is_published' => true,
        ])->assertRedirect();

        $this->get('http://obchod.droidshop/kontakt')
            ->assertStatus(301)
            ->assertRedirect('http://obchod.droidshop/kontakty');
    }

    public function test_a_duplicate_slug_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), [
                'title' => 'Druhý kontakt',
                'slug' => 'kontakt',
                'body' => '<p>Text</p>',
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_deleting_a_page_removes_it_from_the_storefront(): void
    {
        $page = $this->page('kontakt');

        $this->actingAs($this->owner)->put($this->url('/'.$page->id), [
            'title' => 'Kontakt',
            'slug' => 'kontakt',
            'body' => '<p>Text</p>',
            'is_published' => true,
        ])->assertRedirect();

        $this->get('http://obchod.droidshop/kontakt')->assertOk();

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$page->id))
            ->assertRedirect($this->url());

        $this->get('http://obchod.droidshop/kontakt')->assertNotFound();
    }

    public function test_a_page_of_another_shop_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('jiny.droidshop')->create();
        $this->activateModule($other, 'pages');

        $foreign = $this->context->runAs($other, fn () => Page::query()->where('slug', 'kontakt')->firstOrFail());

        $this->actingAs($this->owner)
            ->get($this->url('/'.$foreign->id.'/upravit'))
            ->assertNotFound();
    }

    public function test_a_guest_cannot_write(): void
    {
        $page = $this->page('kontakt');

        $this->put($this->url('/'.$page->id), [
            'title' => 'Hacked',
            'slug' => 'kontakt',
        ])->assertRedirect();

        $this->assertSame('Kontakt', $this->page('kontakt')->title);
    }

    /**
     * The `nova` route has to be registered above /{page}, or Laravel looks
     * for a page with that slug (same reason the product import/export routes
     * sit above /{product}).
     */
    public function test_the_create_route_is_not_mistaken_for_a_page(): void
    {
        $this->actingAs($this->owner)
            ->get($this->url('/nova'))
            ->assertOk();
    }
}
