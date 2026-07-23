<?php

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

/**
 * The gate that keeps the superadmin area out of reach as it grows.
 *
 * Every screen added to routes/platform.php is covered by this automatically —
 * which is the point. A management route that forgets platform.host or
 * auth:platform is a whole-platform breach, not a bug in one screen.
 */
class PlatformRouteIsolationTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
    }

    /**
     * @return list<\Illuminate\Routing\Route>
     */
    private function platformRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route) => str_starts_with((string) $route->getName(), 'platform.'),
        ));
    }

    public function test_every_platform_route_is_restricted_to_the_platform_host(): void
    {
        foreach ($this->platformRoutes() as $route) {
            $this->assertContains(
                'platform.host',
                $route->gatherMiddleware(),
                "Route [{$route->getName()}] is reachable on a tenant host.",
            );
        }
    }

    public function test_every_management_route_sits_behind_the_guard_and_two_factor(): void
    {
        // Login, logout and the 2FA screens are the way in; everything else has
        // to be behind both gates. The Stripe webhook is also unauthenticated by
        // design — Stripe has no session — its authenticity is the signature
        // header, verified in the controller itself.
        $wayIn = ['platform.login', 'platform.logout', 'platform.2fa.setup', 'platform.2fa.challenge', 'platform.stripe.webhook'];

        foreach ($this->platformRoutes() as $route) {
            if (in_array($route->getName(), $wayIn, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:platform', $middleware, "Route [{$route->getName()}] is not behind the platform guard.");
            $this->assertContains('platform.2fa', $middleware, "Route [{$route->getName()}] skips two-factor.");
        }
    }

    public function test_management_routes_are_invisible_from_a_tenant_host(): void
    {
        $this->actingAsPlatformAdmin();
        $tenant = Tenant::factory()->withDomain('kolo.droidshop')->create();

        foreach ([
            '/superadmin',
            '/superadmin/tenanti',
            '/superadmin/tenanti/'.$tenant->uuid,
            '/superadmin/moduly',
        ] as $path) {
            $this->get('http://kolo.droidshop'.$path)
                ->assertNotFound("Path [{$path}] answered on a tenant host.");
        }
    }

    public function test_a_tenant_user_gets_nowhere_on_the_platform_host(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->get($this->platformUrl('/superadmin/tenanti'))->assertRedirect(route('platform.login'));
        $this->get($this->platformUrl('/superadmin/moduly'))->assertRedirect(route('platform.login'));
    }
}
