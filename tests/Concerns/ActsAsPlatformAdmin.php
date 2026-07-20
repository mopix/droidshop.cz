<?php

namespace Tests\Concerns;

use App\Http\Middleware\EnsurePlatformTwoFactor;
use App\Models\PlatformAdmin;

/**
 * Test helper for the superadmin area.
 *
 * Every management screen sits behind three gates: the platform host, the
 * platform guard and a completed two-factor challenge. Tests about tenants and
 * modules should not have to walk through the login flow to get there.
 */
trait ActsAsPlatformAdmin
{
    protected string $platformHost = 'http://droidshop';

    protected function usePlatformHost(): void
    {
        $this->withoutVite();

        config()->set('tenancy.platform_domain', 'droidshop');
    }

    protected function actingAsPlatformAdmin(?PlatformAdmin $admin = null): PlatformAdmin
    {
        $admin ??= PlatformAdmin::factory()->withTwoFactor()->create();

        $this->actingAs($admin, 'platform')
            ->withSession([EnsurePlatformTwoFactor::PASSED_SESSION_KEY => true]);

        return $admin;
    }

    protected function platformUrl(string $path): string
    {
        return $this->platformHost.'/'.ltrim($path, '/');
    }
}
