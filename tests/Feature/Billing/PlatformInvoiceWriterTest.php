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

        $charge = new SubscriptionCharge($this->tenantWithBilling(), $this->plan(), now()->startOfMonth(), now()->endOfMonth());
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
        $a = $w->issue(new SubscriptionCharge($this->tenantWithBilling(), $plan, now()->startOfMonth(), now()->endOfMonth()));
        $b = $w->issue(new SubscriptionCharge($this->tenantWithBilling(), $plan, now()->startOfMonth(), now()->endOfMonth()));

        $this->assertNotSame($a->number, $b->number);
        $this->assertSame(2, PlatformInvoice::count());
    }

    public function test_missing_billing_profile_rejected(): void
    {
        Storage::fake('platform_private');
        $tenant = Tenant::factory()->create(['billing_name' => null]);
        $charge = new SubscriptionCharge($tenant, $this->plan(), now()->startOfMonth(), now()->endOfMonth());

        $this->expectException(MissingBillingProfile::class);
        app(PlatformInvoiceWriter::class)->issue($charge);
    }
}
