<?php

namespace Tests\Feature\Tenant;

use App\Models\Domain;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class AdminHomeTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private function ownerOnHost(): array
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    public function test_owner_hitting_admin_is_redirected_to_a_real_route(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        // "admin.products.index" is a real route: ModuleRouteRegistrar mounts
        // Modules/Products/routes/admin.php at boot regardless of tenant
        // activation, so this is a genuinely resolvable target, not a stub.
        Module::factory()->key('products')->create([
            'manifest' => [
                'name' => 'products',
                'version' => '1.0.0',
                'requires' => [],
                'nav' => [['area' => 'admin', 'label' => 'Produkty', 'route' => 'admin.products.index', 'order' => 10]],
            ],
        ]);
        $this->activateModule($tenant, 'products');

        $response = $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin');

        $this->assertTrue(Route::has('admin.products.index'));
        $response->assertRedirect(route('admin.products.index'));
    }

    public function test_tenant_with_no_active_modules_falls_back_to_billing(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $response = $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin');

        $response->assertRedirect(route('admin.billing.edit'));
    }
}
