<?php

namespace Tests\Feature\Tenant;

use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The generic module settings screen (wave 2.10): one form generated from the
 * schema a module ships, so a new module needs no screen of its own.
 */
class ModuleSettingsTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop.droidshop')->create();

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path): string
    {
        return 'http://shop.droidshop'.$path;
    }

    public function test_the_owner_sees_the_form_built_from_the_schema(): void
    {
        $this->activateModule($this->tenant, 'docs');

        $this->actingAs($this->owner)
            ->get($this->url('/admin/nastaveni/moduly/docs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/ModuleSettings')
                ->where('module.key', 'docs')
                ->has('fields', 7)
                ->where('values.due_days', 14));
    }

    public function test_saving_a_value_takes_effect(): void
    {
        $this->activateModule($this->tenant, 'docs');

        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/moduly/docs'), ['values' => ['due_days' => 30]])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function (): void {
            $this->assertSame(30, app(SettingsService::class)->get('docs', 'due_days'));
        });
    }

    public function test_an_invalid_value_is_rejected_and_nothing_is_written(): void
    {
        $this->activateModule($this->tenant, 'docs');

        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/moduly/docs'), [
                'values' => ['due_days' => 900, 'email_invoice' => false],
            ])
            ->assertSessionHasErrors('values.due_days');

        // All-or-nothing: the valid sibling in the same submission must not
        // land either, or the shop runs a mix of old and new configuration.
        $this->context->runAs($this->tenant, function (): void {
            $settings = app(SettingsService::class);

            $this->assertSame(14, $settings->get('docs', 'due_days'));
            $this->assertTrue($settings->get('docs', 'email_invoice'));
        });
    }

    public function test_an_unknown_key_is_rejected(): void
    {
        $this->activateModule($this->tenant, 'docs');

        $this->actingAs($this->owner)
            ->patch($this->url('/admin/nastaveni/moduly/docs'), ['values' => ['nonsense' => 1]])
            ->assertSessionHasErrors('values');
    }

    public function test_a_module_the_shop_does_not_run_is_not_found(): void
    {
        // `docs` deliberately not activated for this tenant.
        $this->actingAs($this->owner)
            ->get($this->url('/admin/nastaveni/moduly/docs'))
            ->assertNotFound();
    }

    public function test_an_unknown_module_is_not_found(): void
    {
        $this->actingAs($this->owner)
            ->get($this->url('/admin/nastaveni/moduly/nonsense'))
            ->assertNotFound();
    }

    public function test_a_member_without_the_permission_is_forbidden(): void
    {
        $this->activateModule($this->tenant, 'docs');

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => json_encode([]),
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get($this->url('/admin/nastaveni/moduly/docs'))
            ->assertForbidden();

        $this->actingAs($staff)
            ->patch($this->url('/admin/nastaveni/moduly/docs'), ['values' => ['due_days' => 30]])
            ->assertForbidden();
    }

    public function test_the_index_lists_only_modules_with_a_schema(): void
    {
        $this->activateModule($this->tenant, 'docs');
        $this->activateModule($this->tenant, 'categories');

        $this->actingAs($this->owner)
            ->get($this->url('/admin/nastaveni/moduly'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/ModuleSettingsIndex')
                ->has('modules', 1)
                ->where('modules.0.key', 'docs')
                ->where('modules.0.name', 'Doklady'));
    }

    public function test_a_module_the_member_may_not_manage_is_left_out_of_the_index(): void
    {
        $this->activateModule($this->tenant, 'docs');

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => json_encode([]),
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get($this->url('/admin/nastaveni/moduly'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('modules', 0));
    }
}
