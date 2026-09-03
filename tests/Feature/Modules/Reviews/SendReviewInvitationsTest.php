<?php

namespace Tests\Feature\Modules\Reviews;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Orders\Models\Order;
use Modules\Reviews\Models\ReviewInvitation;
use Modules\Reviews\Models\ReviewOptout;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class SendReviewInvitationsTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'reviews');

        Mail::fake();
    }

    /**
     * Objednávka doručená před $daysAgo dny.
     *
     * Tvar je převzatý z tests/Feature/Modules/Orders/OrderAdminTest.php —
     * objednávky se v tomhle projektu nezakládají factory, ale přímo, uvnitř
     * tenant kontextu. Čas doručení musí být v `order_events`, ne v
     * `updated_at`: příkaz čte právě odtamtud.
     */
    private function deliveredOrder(int $daysAgo, string $email = 'kupujici@example.com', ?Tenant $tenant = null): Order
    {
        $tenant ??= $this->tenant;

        return app(TenantContext::class)->runAs($tenant, function () use ($daysAgo, $email, $tenant): Order {
            $order = Order::query()->create([
                'number' => '2026'.random_int(1000, 9999),
                'checkout_token' => Str::random(40),
                'email' => $email,
                'billing' => [
                    'name' => 'Jana Nováková',
                    'street' => 'Hlavní 1',
                    'city' => 'Praha',
                    'zip' => '110 00',
                    'country' => 'CZ',
                ],
                'currency' => 'CZK',
                'items_total' => 10000,
                'total' => 10000,
                'placed_at' => now(),
                'fulfillment_status' => Order::FULFILLMENT_DELIVERED,
            ]);

            DB::table('order_events')->insert([
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'actor_type' => 'system',
                'type' => 'fulfillment',
                'from' => 'shipped',
                'to' => Order::FULFILLMENT_DELIVERED,
                'created_at' => now()->subDays($daysAgo),
            ]);

            return $order;
        });
    }

    public function test_one_invitation_per_order(): void
    {
        $order = $this->deliveredOrder(daysAgo: 8);

        $this->artisan('reviews:send-invitations')->assertSuccessful();
        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $this->assertSame(1, $this->invitationCount($order->id));
    }

    public function test_an_order_delivered_too_recently_gets_nothing(): void
    {
        $this->deliveredOrder(daysAgo: 2);   // výchozí invite_after_days = 7

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $this->assertSame(0, $this->invitationCount());
    }

    public function test_an_opted_out_address_gets_nothing(): void
    {
        $this->deliveredOrder(daysAgo: 8, email: 'nechci@example.com');

        app(TenantContext::class)->runAs($this->tenant, fn () => ReviewOptout::query()->create([
            'email' => 'nechci@example.com',
        ]));

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $this->assertSame(0, $this->invitationCount());
    }

    public function test_the_invitation_is_bulk_mail_so_an_exhausted_quota_stops_it(): void
    {
        // Výzva k recenzi není transakční pošta: když nájemci dojde kvóta,
        // má padnout tahle, ne potvrzení objednávky.
        $this->deliveredOrder(daysAgo: 8);

        $spy = $this->spy(MailService::class);

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $spy->shouldHaveReceived('send')
            ->withArgs(fn ($mailable, $to, MailKind $kind): bool => $kind === MailKind::Bulk);
    }

    public function test_the_stored_token_is_a_hash_not_the_token(): void
    {
        $this->deliveredOrder(daysAgo: 8);

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $invitation = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => ReviewInvitation::query()->firstOrFail()
        );

        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $invitation->token_hash);
    }

    public function test_invitations_can_be_switched_off_per_shop(): void
    {
        // Tvar zápisu nastavení modulu převzatý z testů modulu Docs
        // (např. AutoIssueTest) — SettingsService::set() uvnitř runAs().
        app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(SettingsService::class)->set('reviews', 'invitations_enabled', false)
        );

        $this->deliveredOrder(daysAgo: 8);

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $this->assertSame(0, $this->invitationCount());
    }

    /**
     * Tenant isolation: the sweep loops over every tenant in one command run
     * (Tenant::query()->cursor()), which is exactly the shape where a stray
     * ambient-context bug would leak one shop's invitation into another's
     * count. `TenantScope` is what is actually being trusted here — the
     * command never filters by tenant_id itself.
     */
    public function test_tenant_a_invitation_is_invisible_to_tenant_b(): void
    {
        $tenantB = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($tenantB, 'reviews');

        $orderA = $this->deliveredOrder(daysAgo: 8);
        $orderB = $this->deliveredOrder(daysAgo: 8, tenant: $tenantB);

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $this->assertSame(1, $this->invitationCount(orderId: $orderA->id, tenant: $this->tenant));
        $this->assertSame(1, $this->invitationCount(orderId: $orderB->id, tenant: $tenantB));

        // Each tenant's own scope must not see the other tenant's row.
        $this->assertSame(0, $this->invitationCount(orderId: $orderA->id, tenant: $tenantB));
        $this->assertSame(0, $this->invitationCount(orderId: $orderB->id, tenant: $this->tenant));
    }

    /**
     * `ReviewInvitation` is tenant-scoped (BelongsToTenant): reading it
     * outside TenantContext::runAs() throws MissingTenantContext, the same
     * as tests/Feature/Modules/Docs/AutoIssueTest.php's documentCount().
     */
    private function invitationCount(?int $orderId = null, ?Tenant $tenant = null): int
    {
        return app(TenantContext::class)->runAs(
            $tenant ?? $this->tenant,
            fn () => ReviewInvitation::query()
                ->when($orderId !== null, fn ($query) => $query->where('order_id', $orderId))
                ->count()
        );
    }
}
