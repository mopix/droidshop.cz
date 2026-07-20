<?php

namespace Tests\Feature\Core;

use App\Core\Auth\TenantPermissions;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Manifests declare permissions (spec §5.1). Until now nothing read them, so
 * every declared right was decoration. This is the layer that turns them into
 * an answer to "may this user do that here".
 */
class TenantPermissionsTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantPermissions $permissions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->create();
        $this->permissions = app(TenantPermissions::class);

        $this->activateModule($this->tenant, 'pages');
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();

        $this->tenant->users()->attach($user, ['role' => $role, 'joined_at' => now()]);

        return $user->fresh();
    }

    public function test_permissions_come_from_the_manifests_of_enabled_modules(): void
    {
        $this->assertContains('pages.edit', $this->permissions->availableFor($this->tenant));
    }

    public function test_a_module_the_tenant_does_not_run_grants_nothing(): void
    {
        app(ModuleRegistry::class)->deactivate($this->tenant, 'pages');

        $this->assertNotContains('pages.edit', $this->permissions->availableFor($this->tenant));
    }

    public function test_owner_holds_every_permission_the_shop_has(): void
    {
        $owner = $this->member('owner');

        $this->assertTrue($this->permissions->allows($owner, $this->tenant, 'pages.edit'));
    }

    public function test_staff_holds_only_what_the_membership_lists(): void
    {
        // Staff is phase 2, but the rule is written now so switching it on
        // later is a data change, not a security redesign.
        $staff = $this->member('staff');
        $this->tenant->users()->updateExistingPivot($staff->id, [
            'permissions' => ['pages.view'],
        ]);
        $staff = $staff->fresh();

        $this->assertTrue($this->permissions->allows($staff, $this->tenant, 'pages.view'));
        $this->assertFalse($this->permissions->allows($staff, $this->tenant, 'pages.edit'));
    }

    public function test_a_permission_no_enabled_module_declares_is_refused_even_for_the_owner(): void
    {
        $owner = $this->member('owner');

        $this->assertFalse($this->permissions->allows($owner, $this->tenant, 'products.costs'));
    }

    public function test_non_member_holds_nothing(): void
    {
        $stranger = User::factory()->create();

        $this->assertFalse($this->permissions->allows($stranger, $this->tenant, 'pages.view'));
    }

    public function test_gate_answers_for_the_current_tenant(): void
    {
        $owner = $this->member('owner');

        app(TenantContext::class)->runAs($this->tenant, function () use ($owner) {
            $this->assertTrue(Gate::forUser($owner)->allows('pages.edit'));
            $this->assertFalse(Gate::forUser($owner)->allows('products.costs'));
        });
    }

    public function test_gate_refuses_when_there_is_no_tenant(): void
    {
        $owner = $this->member('owner');

        app(TenantContext::class)->forget();

        $this->assertFalse(Gate::forUser($owner)->allows('pages.edit'));
    }
}
