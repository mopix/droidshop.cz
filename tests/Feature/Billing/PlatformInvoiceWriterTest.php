<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Exceptions\MissingBillingProfile;
use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Billing\PlatformInvoiceWriter;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformInvoiceWriterTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::create(['key' => 'base', 'name' => 'Základní', 'price_month' => 49900, 'price_year' => 499000,
            'level' => 'base', 'is_public' => true, 'limits' => ['products' => 500, 'storage_mb' => 2048, 'emails_month' => 3000]]);
    }

    private function tenantWithBilling(): Tenant
    {
        return Tenant::factory()->create([
            'billing_name' => 'Nájemce s.r.o.', 'billing_ico' => '12345678',
            'billing_dic' => 'CZ12345678', 'vat_payer' => true,
            'billing_address' => ['street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000'],
        ]);
    }

    public function test_issue_creates_numbered_invoice_and_pdf(): void
    {
        Storage::fake('platform_private');
        config()->set('billing.invoice_prefix', 'PF');
        config()->set('billing.vat_rate', 21);

        $charge = new SubscriptionCharge($this->tenantWithBilling(), $this->plan(), now()->startOfMonth(), now()->endOfMonth(), 'in_1', 49900);
        $invoice = app(PlatformInvoiceWriter::class)->issue($charge);

        $this->assertMatchesRegularExpression('/^PF\d{4}0001$/', $invoice->number);
        $this->assertSame('base', $invoice->plan_key);
        $this->assertSame('Nájemce s.r.o.', $invoice->customer['name']);
        $this->assertSame(config('billing.company')['name'], $invoice->supplier['name']);
        $this->assertNotNull($invoice->pdf_path);
        Storage::disk('platform_private')->assertExists($invoice->pdf_path);
    }

    public function test_second_invoice_increments_number(): void
    {
        Storage::fake('platform_private');
        $plan = $this->plan();
        $w = app(PlatformInvoiceWriter::class);
        $a = $w->issue(new SubscriptionCharge($this->tenantWithBilling(), $plan, now()->startOfMonth(), now()->endOfMonth(), 'in_a', 49900));
        $b = $w->issue(new SubscriptionCharge($this->tenantWithBilling(), $plan, now()->startOfMonth(), now()->endOfMonth(), 'in_b', 49900));

        $this->assertNotSame($a->number, $b->number);
        $this->assertSame(2, PlatformInvoice::count());
    }

    public function test_missing_billing_profile_rejected(): void
    {
        Storage::fake('platform_private');
        $tenant = Tenant::factory()->create(['billing_name' => null]);
        $charge = new SubscriptionCharge($tenant, $this->plan(), now()->startOfMonth(), now()->endOfMonth(), 'in_x', 49900);

        $this->expectException(MissingBillingProfile::class);
        app(PlatformInvoiceWriter::class)->issue($charge);
    }

    public function test_vat_payer_platform_splits_vat_out_of_gross(): void
    {
        Storage::fake('platform_private');
        config()->set('billing.company.vat_payer', true);
        config()->set('billing.vat_rate', 21);
        // gross total = 49900 haléře
        $charge = new SubscriptionCharge($this->tenantWithBilling(), $this->plan(), now()->startOfMonth(), now()->endOfMonth(), 'in_vat', 49900);
        $invoice = app(PlatformInvoiceWriter::class)->issue($charge);

        $this->assertSame(41240, $invoice->subtotal); // round(49900/1.21)
        $this->assertSame(8660, $invoice->vat_amount); // 49900-41240
        $this->assertSame(49900, $invoice->total);
        $this->assertSame(41240 + 8660, $invoice->total); // base+vat==total exactly
        $this->assertSame(21, $invoice->vat_rate);
        $this->assertNotEmpty($invoice->vat_summary);
    }

    public function test_issuing_twice_for_same_charge_is_idempotent(): void
    {
        Storage::fake('platform_private');
        $tenant = $this->tenantWithBilling();
        $plan = $this->plan();
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();
        $w = app(PlatformInvoiceWriter::class);

        $a = $w->issue(new SubscriptionCharge($tenant, $plan, $from, $to, 'in_repeat', 49900));
        $b = $w->issue(new SubscriptionCharge($tenant, $plan, $from, $to, 'in_repeat', 49900));

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, PlatformInvoice::count());
    }

    public function test_uses_gross_total_from_the_charge_not_the_plan_price(): void
    {
        $plan = Plan::factory()->create(['price_month' => 49900, 'key' => 'base']);
        $tenant = Tenant::factory()->create(['billing_name' => 'Acme']);

        $charge = new SubscriptionCharge(
            $tenant, $plan, now()->startOfMonth(), now()->endOfMonth(),
            'in_proration', 12300, // proration částka ≠ price_month
        );

        $invoice = app(PlatformInvoiceWriter::class)->issue($charge);

        $this->assertSame(12300, (int) $invoice->total);
        $this->assertSame('in_proration', $invoice->stripe_invoice_id);
    }

    public function test_is_idempotent_per_stripe_invoice_id(): void
    {
        $plan = Plan::factory()->create(['key' => 'base']);
        $tenant = Tenant::factory()->create(['billing_name' => 'Acme']);

        $make = fn () => new SubscriptionCharge(
            $tenant, $plan, now()->startOfMonth(), now()->endOfMonth(), 'in_dup', 49900,
        );

        app(PlatformInvoiceWriter::class)->issue($make());
        app(PlatformInvoiceWriter::class)->issue($make());

        $this->assertSame(1, PlatformInvoice::where('stripe_invoice_id', 'in_dup')->count());
    }
}
