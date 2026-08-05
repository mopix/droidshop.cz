<?php

namespace Tests\Feature\Tenancy;

use App\Core\Billing\Mail\PaymentFailedMail;
use App\Core\Billing\Mail\PendingDeletionMail;
use App\Core\Billing\Mail\ShopReactivatedMail;
use App\Core\Billing\Mail\ShopSuspendedMail;
use App\Core\Billing\Mail\TrialExpiredMail;
use App\Core\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Until wave 3.1 only the lifecycle sweeper told the owner anything, and it
 * did so from its own two call sites. A superadmin suspension and a Stripe
 * payment failure were silent: the nájemce found out by discovering the admin
 * locked. Tenant::changeStatus() now dispatches TenantStatusChanged and
 * SendTenantStatusMail is the only thing that turns a transition into a
 * message.
 */
class TenantStatusMailTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithOwner(TenantStatus $status, ?User $owner = null): array
    {
        $tenant = Tenant::factory()->create(['status' => $status]);
        $owner ??= User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    public function test_suspension_reaches_the_owner(): void
    {
        Mail::fake();
        [$tenant, $owner] = $this->tenantWithOwner(TenantStatus::Active);

        $tenant->changeStatus(TenantStatus::Suspended, 'nezaplaceno');

        Mail::assertSent(
            ShopSuspendedMail::class,
            fn (ShopSuspendedMail $mail) => $mail->hasTo($owner->email),
        );
    }

    /**
     * The same destination, two different stories. trial → past_due means the
     * trial ran out; anything else → past_due means a payment failed, and the
     * owner needs to be told which.
     */
    public function test_past_due_from_active_is_a_payment_failure_not_an_expired_trial(): void
    {
        Mail::fake();
        [$tenant] = $this->tenantWithOwner(TenantStatus::Active);

        $tenant->changeStatus(TenantStatus::PastDue, 'stripe payment failed');

        Mail::assertSent(PaymentFailedMail::class);
        Mail::assertNotSent(TrialExpiredMail::class);
    }

    public function test_past_due_from_trial_is_an_expired_trial(): void
    {
        Mail::fake();
        [$tenant] = $this->tenantWithOwner(TenantStatus::Trial);

        $tenant->changeStatus(TenantStatus::PastDue, 'trial expired');

        Mail::assertSent(TrialExpiredMail::class);
        Mail::assertNotSent(PaymentFailedMail::class);
    }

    public function test_coming_back_from_suspension_reaches_the_owner(): void
    {
        Mail::fake();
        [$tenant] = $this->tenantWithOwner(TenantStatus::Suspended);

        $tenant->changeStatus(TenantStatus::Active, 'paid');

        Mail::assertSent(ShopReactivatedMail::class);
    }

    /**
     * The first payment already gets Stripe's own receipt plus a Czech tax
     * document from PlatformInvoiceWriter; a third message saying the same
     * thing is noise.
     */
    public function test_trial_to_active_stays_silent(): void
    {
        Mail::fake();
        [$tenant] = $this->tenantWithOwner(TenantStatus::Trial);

        $tenant->changeStatus(TenantStatus::Active, 'stripe invoice paid');

        Mail::assertNothingSent();
    }

    public function test_pending_deletion_carries_the_reason(): void
    {
        Mail::fake();
        [$tenant] = $this->tenantWithOwner(TenantStatus::Suspended);

        $tenant->changeStatus(TenantStatus::PendingDeletion, 'na žádost majitele');

        Mail::assertSent(
            PendingDeletionMail::class,
            fn (PendingDeletionMail $mail) => $mail->reason === 'na žádost majitele',
        );
    }

    public function test_a_no_op_transition_sends_nothing(): void
    {
        Mail::fake();
        [$tenant] = $this->tenantWithOwner(TenantStatus::Active);

        $tenant->changeStatus(TenantStatus::Active);

        Mail::assertNothingSent();
    }

    /**
     * StripeWebhookHandler changes status inside the transaction that also
     * carries its idempotency claim. An inline dispatch would mail the owner
     * about a change that then rolled back — hence DB::afterCommit.
     */
    public function test_a_rolled_back_transition_sends_nothing(): void
    {
        Mail::fake();
        [$tenant] = $this->tenantWithOwner(TenantStatus::Active);

        try {
            DB::transaction(function () use ($tenant): void {
                $tenant->changeStatus(TenantStatus::Suspended, 'nezaplaceno');

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Mail::assertNothingSent();
        $this->assertSame(TenantStatus::Active, $tenant->fresh()->status);
    }

    public function test_a_tenant_without_an_owner_does_not_blow_up(): void
    {
        Mail::fake();
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);

        $tenant->changeStatus(TenantStatus::Suspended, 'nezaplaceno');

        Mail::assertNothingSent();
        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()->status);
    }
}
