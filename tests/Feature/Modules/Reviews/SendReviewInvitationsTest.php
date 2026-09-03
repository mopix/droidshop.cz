<?php

namespace Tests\Feature\Modules\Reviews;

use App\Core\Customers\Contracts\CustomerIdentity;
use App\Core\Enums\TenantStatus;
use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Customers\Models\Customer;
use Modules\Orders\Models\Order;
use Modules\Reviews\Mail\ReviewInvitationMail;
use Modules\Reviews\Models\ReviewInvitation;
use Modules\Reviews\Models\ReviewOptout;
use RuntimeException;
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

        // The riskier leak: both tenants own a primary domain and the queue
        // is sync, so the mailable really renders here. Every link in the
        // e-mail must carry that tenant's own host, not the platform's
        // (a dropped forceRootUrl) and not the other tenant's (a shared
        // mutable root left over between two runAs() iterations).
        Mail::assertSent(
            ReviewInvitationMail::class,
            fn (ReviewInvitationMail $mail): bool => str_contains($mail->reviewUrl, 'shop1.droidshop')
                && str_contains($mail->optoutUrl, 'shop1.droidshop')
        );

        Mail::assertSent(
            ReviewInvitationMail::class,
            fn (ReviewInvitationMail $mail): bool => str_contains($mail->reviewUrl, 'shop2.droidshop')
                && str_contains($mail->optoutUrl, 'shop2.droidshop')
        );
    }

    /**
     * TenantStatus::allowsStorefront() is false for Suspended (also
     * PendingDeletion and Deleted, not exercised separately here — all three
     * share the same branch): a dead shop's customers must not be mailed a
     * link to a storefront that will not answer, and it must not burn the
     * shop's emails_month quota doing it.
     */
    public function test_a_suspended_tenant_gets_nothing(): void
    {
        $order = $this->deliveredOrder(daysAgo: 8);

        $this->tenant->changeStatus(TenantStatus::Suspended, 'test');

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $this->assertSame(0, $this->invitationCount($order->id));
    }

    /**
     * The storefront routes sit behind `module:reviews`, but this sweep
     * loops over every tenant directly and never goes through that route
     * gate — a tenant who switched the module off must not have a customer
     * mailed a link that 404s, at his own quota's expense.
     */
    public function test_a_tenant_that_deactivated_the_module_gets_nothing(): void
    {
        $order = $this->deliveredOrder(daysAgo: 8);

        app(ModuleRegistry::class)->deactivate($this->tenant, 'reviews');

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $this->assertSame(0, $this->invitationCount($order->id));
    }

    /**
     * CustomerEraser anonymises the customer row but deliberately leaves
     * orders.email as an accounting record — so the order's own email
     * cannot be trusted to answer "was this buyer erased?". This exercises
     * the real EloquentCustomerIdentity, not a mock: an anonymised row must
     * come back null from findById(), and that null must suppress the
     * invitation.
     */
    public function test_an_erased_customer_gets_nothing(): void
    {
        $this->activateModule($this->tenant, 'customers');

        $customer = app(TenantContext::class)->runAs($this->tenant, fn () => Customer::query()->create([
            'email' => 'erased@example.com',
            'password' => Hash::make('irrelevant'),
            'anonymised_at' => now(),
        ]));

        $order = app(TenantContext::class)->runAs($this->tenant, function () use ($customer): Order {
            $order = $this->deliveredOrder(daysAgo: 8, email: 'erased@example.com');
            $order->update(['customer_id' => $customer->id]);

            return $order;
        });

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $this->assertSame(0, $this->invitationCount($order->id));
    }

    /**
     * The per-order body is wrapped in its own try/catch: a failure looking
     * up one order's customer (a database hiccup, in production — a mocked
     * throw here) must not take a sibling order in the same tenant down
     * with it, and must not leave the sweep exiting non-zero.
     */
    public function test_one_order_failing_does_not_stop_the_others(): void
    {
        $this->activateModule($this->tenant, 'customers');

        $this->mock(CustomerIdentity::class, function ($mock): void {
            $mock->shouldReceive('findById')->with(999)->andThrow(new RuntimeException('boom'));
        });

        $brokenOrder = app(TenantContext::class)->runAs($this->tenant, function (): Order {
            $order = $this->deliveredOrder(daysAgo: 8, email: 'broken@example.com');
            $order->update(['customer_id' => 999]);

            return $order;
        });

        $guestOrder = $this->deliveredOrder(daysAgo: 8, email: 'guest@example.com');

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        $this->assertSame(0, $this->invitationCount($brokenOrder->id));
        $this->assertSame(1, $this->invitationCount($guestOrder->id));
    }

    /**
     * The half of finding 4 that actually matters: issue() writes the
     * review_invitations row before send() ever runs. A transient failure
     * out of send() itself (SMTP, network, a MailMessage insert failing —
     * simulated here as a generic RuntimeException, not MailLimitReached)
     * must not permanently cost the buyer their invitation. The row has to
     * survive unsent, and the next day's sweep has to be able to retry the
     * same order — which means deliveredOrdersDue() must key its exclusion
     * on sent_at, not on the row's mere existence, and issue() must reuse
     * that row (fresh token, fresh expiry) rather than blindly create() a
     * second one that unique(tenant_id, order_id) would reject.
     */
    public function test_a_mail_send_failure_leaves_the_order_invitable_on_retry(): void
    {
        $order = $this->deliveredOrder(daysAgo: 8);

        $attempt = 0;
        $this->mock(MailService::class, function ($mock) use (&$attempt): void {
            $mock->shouldReceive('send')->andReturnUsing(function () use (&$attempt): MailMessage {
                $attempt++;

                if ($attempt === 1) {
                    throw new RuntimeException('smtp blip');
                }

                return new MailMessage;
            });
        });

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        // First attempt: issue() already wrote the row before send() blew
        // up. It must still be there, still unsent.
        $failed = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => ReviewInvitation::query()->where('order_id', $order->id)->firstOrFail()
        );

        $this->assertNull($failed->sent_at);

        $this->artisan('reviews:send-invitations')->assertSuccessful();

        // Retry: exactly one row for this order (not two — a blind
        // create() on the second issue() would have violated
        // unique(tenant_id, order_id)), now sent, and carrying a rotated
        // token — the one from the failed attempt was never delivered to
        // anybody, so reusing it would be a live link nobody has yet.
        $this->assertSame(1, $this->invitationCount($order->id));

        $retried = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => ReviewInvitation::query()->where('order_id', $order->id)->firstOrFail()
        );

        $this->assertNotNull($retried->sent_at);
        $this->assertNotSame($failed->token_hash, $retried->token_hash);
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
