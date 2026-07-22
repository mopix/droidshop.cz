<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Models\PlatformInvoice;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformInvoiceDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(Tenant $tenant): PlatformInvoice
    {
        Storage::fake('platform_private');
        Storage::disk('platform_private')->put('billing/PF20260001.pdf', '%PDF-1.4 fake');

        return PlatformInvoice::create([
            'number' => 'PF20260001', 'billed_tenant_id' => $tenant->id,
            'supplier' => ['name' => 'P'], 'customer' => ['name' => 'N'], 'plan_key' => 'base',
            'period_from' => now()->startOfMonth(), 'period_to' => now()->endOfMonth(),
            'subtotal' => 49900, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 49900, 'vat_summary' => [],
            'issued_at' => now(), 'taxable_at' => now(), 'pdf_path' => 'billing/PF20260001.pdf',
        ]);
    }

    public function test_tenant_owner_downloads_own_invoice(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);
        $invoice = $this->invoiceFor($tenant);

        $this->actingAs($owner)
            ->get("http://shop.".config('tenancy.platform_domain')."/admin/predplatne/faktury/{$invoice->id}/pdf")
            ->assertOk();
    }

    public function test_tenant_cannot_download_foreign_invoice(): void
    {
        $mine = Tenant::factory()->create();
        Domain::create(['tenant_id' => $mine->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $mine->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $other = Tenant::factory()->create();
        $foreign = $this->invoiceFor($other);

        $this->actingAs($owner)
            ->get("http://shop.".config('tenancy.platform_domain')."/admin/predplatne/faktury/{$foreign->id}/pdf")
            ->assertNotFound();
    }
}
