<?php

namespace Tests\Feature\Onboarding;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ShopEntryTest extends TestCase
{
    use RefreshDatabase;

    private function tenantOnHost(User $owner): Tenant
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return $tenant;
    }

    /**
     * Laravel's signature covers the full absolute URL, host included (same
     * fact FileStorage::signedUrl and ImpersonationController::start rely on),
     * so the URL must be signed on the tenant host directly. Building it on
     * whatever host is "current" during the test and then rewriting the path
     * onto the tenant host afterwards produces a signature that never
     * validates — the root has to be forced before signing, not swapped after.
     */
    private function entryUrl(User $user, string $host): string
    {
        $previous = URL::to('/');
        URL::forceRootUrl('http://'.$host);

        try {
            return URL::temporarySignedRoute('onboarding.enter', now()->addMinutes(5), ['user' => $user->id]);
        } finally {
            URL::forceRootUrl($previous);
        }
    }

    public function test_signed_entry_logs_owner_in_on_tenant_host(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->tenantOnHost($owner);

        $url = $this->entryUrl($owner, 'shop.'.config('tenancy.platform_domain'));

        $response = $this->get($url);
        $response->assertRedirect();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_rejects_non_member(): void
    {
        $owner = User::factory()->create();
        $this->tenantOnHost($owner);
        $stranger = User::factory()->create();

        $url = $this->entryUrl($stranger, 'shop.'.config('tenancy.platform_domain'));

        $this->get($url)->assertForbidden();
    }
}
