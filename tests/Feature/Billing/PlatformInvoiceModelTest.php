<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Models\PlatformInvoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformInvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    private function make(): PlatformInvoice
    {
        $tenant = Tenant::factory()->create();

        return PlatformInvoice::create([
            'number' => 'PF20260001',
            'billed_tenant_id' => $tenant->id,
            'supplier' => ['name' => 'Platforma', 'ico' => '123'],
            'customer' => ['name' => 'Nájemce', 'ico' => '456'],
            'plan_key' => 'base',
            'period_from' => now()->startOfMonth(),
            'period_to' => now()->endOfMonth(),
            'subtotal' => 41240,
            'vat_rate' => 21,
            'vat_amount' => 8660,
            'total' => 49900,
            'vat_summary' => [['rate' => 21, 'base' => 41240, 'vat' => 8660]],
            'issued_at' => now(),
            'taxable_at' => now(),
        ]);
    }

    public function test_can_create_and_cast(): void
    {
        $inv = $this->make();
        $this->assertSame('base', $inv->plan_key);
        $this->assertIsArray($inv->customer);
        $this->assertSame(49900, $inv->total);
    }

    public function test_delete_is_blocked(): void
    {
        $inv = $this->make();
        $this->expectException(\RuntimeException::class);
        $inv->delete();
    }

    public function test_body_update_blocked_but_pdf_path_allowed(): void
    {
        $inv = $this->make();
        $inv->update(['pdf_path' => 'billing/PF20260001.pdf']); // allowed
        $this->assertSame('billing/PF20260001.pdf', $inv->fresh()->pdf_path);

        $this->expectException(\RuntimeException::class);
        $inv->update(['total' => 1]); // blocked
    }
}
