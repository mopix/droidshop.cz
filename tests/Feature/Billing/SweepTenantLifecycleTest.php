<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Mail\ShopSuspendedMail;
use App\Core\Billing\Mail\TrialExpiredMail;
use App\Core\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SweepTenantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_trial_moves_to_past_due(): void
    {
        Mail::fake();
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->artisan('billing:sweep-lifecycle')->assertSuccessful();

        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }

    /**
     * changeStatus() writes its own audit entry via ambient TenantContext, so
     * the sweep has to run it inside TenantContext::runAs() — otherwise
     * AuditLog::log() derives tenant_id from a null ambient context and the
     * dunning trail is unattributed (finding: SweepTenantLifecycle wave 1.7
     * review).
     */
    public function test_expired_trial_audit_entry_carries_tenant_id(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->artisan('billing:sweep-lifecycle')->assertSuccessful();

        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $tenant->id,
            'action' => 'tenant.status_changed',
        ]);
    }

    public function test_active_trial_untouched(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Trial, 'trial_ends_at' => now()->addDays(5),
        ]);

        $this->artisan('billing:sweep-lifecycle');
        $this->assertSame(TenantStatus::Trial, $tenant->fresh()->status);
    }

    public function test_past_due_beyond_grace_suspends(): void
    {
        config()->set('billing.grace_days', 7);
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::PastDue,
            'trial_ends_at' => now()->subDays(8), // grace exceeded
        ]);

        $this->artisan('billing:sweep-lifecycle');
        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()->status);
    }

    public function test_past_due_within_grace_untouched(): void
    {
        config()->set('billing.grace_days', 7);
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::PastDue, 'trial_ends_at' => now()->subDays(3),
        ]);

        $this->artisan('billing:sweep-lifecycle');
        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }

    public function test_idempotent_second_run(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Trial, 'trial_ends_at' => now()->subDay()]);
        $this->artisan('billing:sweep-lifecycle');
        $this->artisan('billing:sweep-lifecycle'); // no crash, stays past_due
        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }

    public function test_expired_trial_emails_owner(): void
    {
        Mail::fake();
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->subDay(),
        ]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->artisan('billing:sweep-lifecycle')->assertSuccessful();

        Mail::assertSent(TrialExpiredMail::class, function (TrialExpiredMail $mail) use ($owner): bool {
            return $mail->hasTo($owner->email);
        });
    }

    public function test_suspended_shop_emails_owner(): void
    {
        Mail::fake();
        config()->set('billing.grace_days', 7);
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::PastDue,
            'trial_ends_at' => now()->subDays(8), // grace exceeded
        ]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->artisan('billing:sweep-lifecycle')->assertSuccessful();

        Mail::assertSent(ShopSuspendedMail::class, function (ShopSuspendedMail $mail) use ($owner): bool {
            return $mail->hasTo($owner->email);
        });
    }

    public function test_stripe_managed_tenant_not_suspended_despite_expired_date(): void
    {
        Mail::fake();
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::PastDue,
            'trial_ends_at' => now()->subDays(30),
            'stripe_subscription_id' => 'sub_test_123',
        ]);

        $this->artisan('billing:sweep-lifecycle')->assertSuccessful();

        // Stripe-managed tenant should NOT be suspended; lifecycle is driven by webhooks
        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
        Mail::assertNotSent(ShopSuspendedMail::class);
    }

    public function test_pure_trial_tenant_still_swept(): void
    {
        Mail::fake();
        $tenant = Tenant::factory()->create([
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->subDay(),
            'stripe_subscription_id' => null,
        ]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->artisan('billing:sweep-lifecycle')->assertSuccessful();

        // Pure-trial tenants (no Stripe subscription) should still be swept
        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
        Mail::assertSent(TrialExpiredMail::class);
    }
}
