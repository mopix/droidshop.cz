<?php

namespace Tests\Feature\PageCache;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class FlushCacheTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    public function test_the_owner_can_flush_every_dimension(): void
    {
        $before = $this->tenant->fresh();

        $this->actingAs($this->owner)
            ->post('http://obchod.droidshop/admin/nastaveni/vzhled/cache')
            ->assertRedirect();

        $after = $this->tenant->fresh();

        $this->assertGreaterThan((int) $before->page_gen_catalog, (int) $after->page_gen_catalog);
        $this->assertGreaterThan((int) $before->page_gen_content, (int) $after->page_gen_content);
        $this->assertGreaterThan((int) $before->page_gen_theme, (int) $after->page_gen_theme);
    }

    public function test_a_stranger_cannot_flush_someone_elses_shop(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post('http://obchod.droidshop/admin/nastaveni/vzhled/cache')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->post('http://obchod.droidshop/admin/nastaveni/vzhled/cache')
            ->assertRedirect();
    }
}
