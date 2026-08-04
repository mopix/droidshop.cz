<?php

namespace Tests\Feature\PageCache;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class RedirectLookupCacheTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($tenant, 'storefront');
    }

    public function test_a_repeated_miss_does_not_query_the_redirect_table_again(): void
    {
        $this->get('http://obchod.droidshop/wp-admin/setup-config.php')->assertNotFound();

        $hits = 0;
        DB::listen(function ($query) use (&$hits): void {
            if (str_contains($query->sql, 'redirects')) {
                $hits++;
            }
        });

        $this->get('http://obchod.droidshop/wp-admin/setup-config.php')->assertNotFound();

        // Scanners hammer paths like this. The lookup is pure catalogue data,
        // so it belongs behind the same generation as everything else.
        $this->assertSame(0, $hits);
    }
}
