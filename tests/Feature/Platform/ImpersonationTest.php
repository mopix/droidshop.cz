<?php

namespace Tests\Feature\Platform;

use App\Core\Platform\Impersonation;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use App\Models\AuditLogEntry;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private PlatformAdmin $admin;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->admin = PlatformAdmin::factory()->withTwoFactor()->create();
        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner->id, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function beginUrl(User $user, PlatformAdmin $admin, string $domain = 'shop1.droidshop'): string
    {
        $previous = URL::to('/');
        URL::forceRootUrl('https://'.$domain);

        try {
            return URL::temporarySignedRoute('impersonation.begin', now()->addMinutes(5), [
                'user' => $user->id,
                'admin' => $admin->id,
            ]);
        } finally {
            URL::forceRootUrl($previous);
        }
    }

    public function test_superadmin_starts_impersonation_and_is_redirected_to_the_tenant(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->withSession(['platform.2fa_passed' => true]);

        $response = $this->post('http://droidshop/superadmin/impersonace', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('shop1.droidshop', $response->headers->get('Location'));
        $this->assertStringContainsString('/impersonace/zahajit/', $response->headers->get('Location'));
    }

    public function test_starting_from_an_inertia_screen_hands_the_url_to_the_browser(): void
    {
        // The button lives on an Inertia page. A plain redirect would be
        // followed by axios into a cross-origin request the tenant domain does
        // not answer, so the visit has to be handed back to the browser.
        $this->actingAs($this->admin, 'platform')
            ->withSession(['platform.2fa_passed' => true]);

        $response = $this->post('http://droidshop/superadmin/impersonace', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
        ], ['X-Inertia' => 'true']);

        $response->assertStatus(409);
        $this->assertStringContainsString('shop1.droidshop', $response->headers->get('X-Inertia-Location'));
    }

    public function test_cannot_impersonate_a_user_outside_the_tenant(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->admin, 'platform')
            ->withSession(['platform.2fa_passed' => true]);

        $this->post('http://droidshop/superadmin/impersonace', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $stranger->id,
        ])->assertForbidden();
    }

    public function test_signed_begin_url_logs_in_as_the_target_and_records_it(): void
    {
        $url = $this->beginUrl($this->owner, $this->admin);

        $this->get($url)->assertRedirect('/');

        $this->assertAuthenticatedAs($this->owner, 'web');

        $entry = AuditLogEntry::where('action', 'impersonation.started')->firstOrFail();
        $this->assertSame($this->admin->id, $entry->impersonated_by);
        $this->assertSame($this->tenant->id, $entry->tenant_id);
    }

    public function test_every_action_while_impersonating_carries_impersonated_by(): void
    {
        // Establish impersonation, then log an unrelated action: it must still
        // carry the impersonator.
        $this->get($this->beginUrl($this->owner, $this->admin));

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            app(AuditLog::class)->log('products.updated');
        });

        $entry = AuditLogEntry::where('action', 'products.updated')->firstOrFail();
        $this->assertSame($this->admin->id, $entry->impersonated_by);
    }

    public function test_unsigned_begin_url_is_rejected(): void
    {
        $this->get("http://shop1.droidshop/impersonace/zahajit/{$this->owner->id}/{$this->admin->id}")
            ->assertForbidden();
    }

    public function test_impersonation_expires_after_thirty_minutes(): void
    {
        $this->get($this->beginUrl($this->owner, $this->admin));

        $impersonation = app(Impersonation::class);
        $this->assertTrue($impersonation->isActive());

        $this->travel(31)->minutes();

        $this->assertFalse($impersonation->isActive(), 'Impersonation is a support action, not a place to live.');
        $this->assertNull($impersonation->impersonatorId());
    }

    public function test_stopping_impersonation_clears_it(): void
    {
        $this->get($this->beginUrl($this->owner, $this->admin));
        $this->assertTrue(app(Impersonation::class)->isActive());

        $this->post('http://shop1.droidshop/impersonace/ukoncit')->assertRedirect('/');

        $this->assertFalse(app(Impersonation::class)->isActive());
        $this->assertGuest('web');

        $this->assertDatabaseHas('audit_log', ['action' => 'impersonation.stopped']);
    }

    public function test_impersonation_cannot_be_started_without_platform_auth(): void
    {
        // No platform session at all.
        $this->post('http://droidshop/superadmin/impersonace', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
        ])->assertRedirect(route('platform.login'));
    }
}
