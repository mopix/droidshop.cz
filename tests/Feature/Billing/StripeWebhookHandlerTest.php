<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Exceptions\MissingBillingProfile;
use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Billing\Models\StripeEvent;
use App\Core\Billing\StripeWebhookHandler;
use App\Core\Billing\TenantPlanSwitcher;
use App\Core\Enums\TenantStatus;
use App\Core\Modules\ModuleRegistry;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Event;
use Tests\TestCase;

class StripeWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Registry results are cached for the kill switch to stay quick;
        // tests must not read a neighbouring test's registry.
        config()->set('cache.default', 'array');
    }

    private function stripeEvent(string $type, array $object, string $id = 'evt_1'): Event
    {
        return Event::constructFrom([
            'id' => $id,
            'type' => $type,
            'data' => ['object' => $object],
        ]);
    }

    public function test_links_customer_and_subscription_on_checkout_session_completed(): void
    {
        $tenant = Tenant::factory()->create(['stripe_customer_id' => null, 'stripe_subscription_id' => null]);

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('checkout.session.completed', [
            'customer' => 'cus_x',
            'subscription' => 'sub_x',
            'metadata' => ['tenant_id' => (string) $tenant->id],
        ]));

        $tenant->refresh();
        $this->assertSame('cus_x', $tenant->stripe_customer_id);
        $this->assertSame('sub_x', $tenant->stripe_subscription_id);
    }

    public function test_issues_our_invoice_and_activates_on_invoice_paid(): void
    {
        $plan = Plan::factory()->create(['price_month' => 49900]);
        PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
        $tenant = Tenant::factory()->create([
            'plan_id' => $plan->id,
            'billing_name' => 'Acme',
            'status' => TenantStatus::Trial,
            'stripe_customer_id' => 'cus_x',
            'stripe_subscription_id' => 'sub_x',
        ]);

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.paid', [
            'id' => 'in_1',
            'customer' => 'cus_x',
            'subscription' => 'sub_x',
            'amount_paid' => 49900,
            'lines' => ['data' => [['period' => ['start' => 1751328000, 'end' => 1753920000], 'price' => ['id' => 'price_m']]]],
        ]));

        $tenant->refresh();
        $this->assertSame(TenantStatus::Active, $tenant->status);
        $this->assertSame(1, PlatformInvoice::where('billed_tenant_id', $tenant->id)->count());
    }

    public function test_is_idempotent_per_stripe_event_id(): void
    {
        $plan = Plan::factory()->create(['price_month' => 49900]);
        PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
        $tenant = Tenant::factory()->create([
            'plan_id' => $plan->id, 'billing_name' => 'Acme',
            'stripe_customer_id' => 'cus_x', 'stripe_subscription_id' => 'sub_x',
        ]);
        $object = [
            'id' => 'in_1',
            'customer' => 'cus_x', 'subscription' => 'sub_x',
            'amount_paid' => 49900,
            'lines' => ['data' => [['period' => ['start' => 1751328000, 'end' => 1753920000], 'price' => ['id' => 'price_m']]]],
        ];

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.paid', $object, 'evt_dup'));
        app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.paid', $object, 'evt_dup'));

        $this->assertSame(1, PlatformInvoice::where('billed_tenant_id', $tenant->id)->count());
        $this->assertSame(1, StripeEvent::where('event_id', 'evt_dup')->count());
    }

    public function test_invoice_amount_drives_our_document_not_the_plan_price(): void
    {
        $plan = Plan::factory()->create(['price_month' => 49900]);
        PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
        $tenant = Tenant::factory()->create([
            'plan_id' => $plan->id, 'billing_name' => 'Acme',
            'stripe_customer_id' => 'cus_x', 'status' => TenantStatus::Trial,
        ]);

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.paid', [
            'id' => 'in_proration', 'customer' => 'cus_x', 'amount_paid' => 15000,
            'lines' => ['data' => [['period' => ['start' => 1751328000, 'end' => 1753920000], 'price' => ['id' => 'price_m']]]],
        ], 'evt_pr'));

        $invoice = PlatformInvoice::where('billed_tenant_id', $tenant->id)->first();
        $this->assertSame(15000, (int) $invoice->total);
        $this->assertSame('in_proration', $invoice->stripe_invoice_id);
    }

    public function test_zero_amount_invoice_issues_no_document(): void
    {
        $plan = Plan::factory()->create();
        PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'billing_name' => 'Acme', 'stripe_customer_id' => 'cus_x']);

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.paid', [
            'id' => 'in_zero', 'customer' => 'cus_x', 'amount_paid' => 0,
            'lines' => ['data' => [['period' => ['start' => 1751328000, 'end' => 1753920000], 'price' => ['id' => 'price_m']]]],
        ], 'evt_zero'));

        $this->assertSame(0, PlatformInvoice::where('billed_tenant_id', $tenant->id)->count());
    }

    public function test_moves_tenant_to_past_due_on_payment_failure(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active, 'stripe_customer_id' => 'cus_x']);

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.payment_failed', ['customer' => 'cus_x']));

        $this->assertSame(TenantStatus::PastDue, $tenant->fresh()->status);
    }

    public function test_suspends_tenant_on_subscription_deleted(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PastDue, 'stripe_customer_id' => 'cus_x']);

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('customer.subscription.deleted', ['customer' => 'cus_x']));

        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()->status);
    }

    public function test_ignores_an_event_for_an_unknown_customer_without_throwing(): void
    {
        app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.payment_failed', ['customer' => 'cus_missing']));

        $this->assertTrue(true);
    }

    public function test_idempotency_claim_is_atomic_with_processing_and_a_dropped_retry_still_succeeds(): void
    {
        $plan = Plan::factory()->create(['price_month' => 49900]);
        PlanPrice::create(['plan_id' => $plan->id, 'interval' => 'month', 'stripe_price_id' => 'price_m', 'price_amount' => 49900, 'currency' => 'CZK']);
        $tenant = Tenant::factory()->create([
            'plan_id' => $plan->id,
            'billing_name' => '',
            'status' => TenantStatus::Trial,
            'stripe_customer_id' => 'cus_x',
            'stripe_subscription_id' => 'sub_x',
        ]);
        $object = [
            'id' => 'in_1',
            'customer' => 'cus_x', 'subscription' => 'sub_x',
            'amount_paid' => 49900,
            'lines' => ['data' => [['period' => ['start' => 1751328000, 'end' => 1753920000], 'price' => ['id' => 'price_m']]]],
        ];

        try {
            app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.paid', $object, 'evt_retry'));
            $this->fail('Expected MissingBillingProfile to be thrown.');
        } catch (MissingBillingProfile) {
            // Expected: no billing profile yet.
        }

        // The claim insert rolled back with the failed processing — nothing left behind.
        $this->assertSame(0, StripeEvent::where('event_id', 'evt_retry')->count());
        $this->assertSame(0, PlatformInvoice::where('billed_tenant_id', $tenant->id)->count());

        // Fix the profile and let Stripe redeliver the same event id.
        $tenant->forceFill(['billing_name' => 'Acme'])->save();

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('invoice.paid', $object, 'evt_retry'));

        $tenant->refresh();
        $this->assertSame(TenantStatus::Active, $tenant->status);
        $this->assertSame(1, PlatformInvoice::where('billed_tenant_id', $tenant->id)->count());
        $this->assertSame(1, StripeEvent::where('event_id', 'evt_retry')->count());
    }

    public function test_unknown_event_type_processes_without_throwing_and_records_one_row(): void
    {
        app(StripeWebhookHandler::class)->handle($this->stripeEvent('customer.updated', ['id' => 'cus_x'], 'evt_unknown'));

        $this->assertSame(1, StripeEvent::where('event_id', 'evt_unknown')->count());
    }

    public function test_subscription_updated_switches_plan_and_reconciles_modules(): void
    {
        [$base, $premium, $baseKey, $premiumOnlyKey] = $this->seedPlans();
        PlanPrice::create(['plan_id' => $premium->id, 'interval' => 'month', 'stripe_price_id' => 'price_prem_m', 'price_amount' => 99900, 'currency' => 'CZK']);

        $tenant = Tenant::factory()->create(['plan_id' => $base->id, 'stripe_customer_id' => 'cus_x']);
        app(TenantPlanSwitcher::class)->switchTo($tenant, $base, BillingInterval::Month);

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('customer.subscription.updated', [
            'customer' => 'cus_x',
            'items' => ['data' => [['price' => ['id' => 'price_prem_m']]]],
        ], 'evt_upd'));

        $tenant->refresh();
        $this->assertSame($premium->id, $tenant->plan_id);
        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($tenant, $premiumOnlyKey));
    }

    public function test_subscription_updated_for_unknown_price_is_a_no_op(): void
    {
        $tenant = Tenant::factory()->create(['stripe_customer_id' => 'cus_x']);
        $before = $tenant->plan_id;

        app(StripeWebhookHandler::class)->handle($this->stripeEvent('customer.subscription.updated', [
            'customer' => 'cus_x',
            'items' => ['data' => [['price' => ['id' => 'price_unknown']]]],
        ], 'evt_upd2'));

        $this->assertSame($before, $tenant->fresh()->plan_id);
    }

    /**
     * Two plans that share one module and differ by exactly one more —
     * mirrors TenantPlanSwitcherTest::seedPlans().
     *
     * @return array{0: Plan, 1: Plan, 2: string, 3: string}
     */
    private function seedPlans(): array
    {
        $baseModule = Module::factory()->key('base-module')->create();
        $premiumModule = Module::factory()->key('premium-module')->create();

        $base = Plan::factory()->create();
        $base->modules()->attach($baseModule->key);

        $premium = Plan::factory()->premium()->create();
        $premium->modules()->attach([$baseModule->key, $premiumModule->key]);

        return [$base, $premium, $baseModule->key, $premiumModule->key];
    }
}
