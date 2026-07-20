<?php

namespace Tests\Feature\Modules\Customers;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Services\CustomerTokens;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class CustomerTokenPruningTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');
        $this->artisan('modules:sync')->assertSuccessful();
        app(TenantContext::class)->forget();
    }

    public function test_the_prune_command_deletes_only_expired_tokens_across_every_tenant(): void
    {
        $tenantA = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $tenantB = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($tenantA, 'customers');
        $this->activateModule($tenantB, 'customers');

        DB::table('customer_tokens')->insert([
            [
                'tenant_id' => $tenantA->id,
                'email' => 'stary@example.test',
                'purpose' => CustomerTokens::PASSWORD_RESET,
                'token_hash' => hash('sha256', 'a'),
                'expires_at' => now()->subDay(),
                'created_at' => now()->subDay(),
            ],
            [
                'tenant_id' => $tenantB->id,
                'email' => 'cerstvy@example.test',
                'purpose' => CustomerTokens::EMAIL_VERIFICATION,
                'token_hash' => hash('sha256', 'b'),
                'expires_at' => now()->addHour(),
                'created_at' => now(),
            ],
        ]);

        $this->artisan('customers:prune-tokens')->assertSuccessful();

        $this->assertSame(0, DB::table('customer_tokens')->where('email', 'stary@example.test')->count());
        $this->assertSame(1, DB::table('customer_tokens')->where('email', 'cerstvy@example.test')->count());
    }

    public function test_the_prune_command_is_scheduled_to_run_daily(): void
    {
        $schedule = app(Schedule::class);

        $matching = collect($schedule->events())->first(
            fn ($event) => str_contains((string) ($event->command ?? ''), 'customers:prune-tokens')
        );

        $this->assertNotNull($matching, 'customers:prune-tokens is not registered on the schedule.');
        $this->assertSame('0 0 * * *', $matching->expression);
    }
}
