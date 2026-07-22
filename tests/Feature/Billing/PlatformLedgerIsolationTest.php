<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformLedgerIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_invoice_is_not_tenant_scoped(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        foreach ([$a, $b] as $i => $t) {
            PlatformInvoice::create([
                'number' => 'PF2026000'.($i + 1), 'billed_tenant_id' => $t->id,
                'supplier' => ['name' => 'P'], 'customer' => ['name' => 'N'], 'plan_key' => 'base',
                'period_from' => now()->startOfMonth(), 'period_to' => now()->endOfMonth(),
                'subtotal' => 1, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 1, 'vat_summary' => [],
                'issued_at' => now(), 'taxable_at' => now(),
            ]);
        }

        // Make tenant A current: a tenant-scoped model would now hide B's row.
        app(TenantContext::class)->runAs($a, function () {
            $this->assertSame(2, PlatformInvoice::count(), 'Platform ledger must not be tenant-scoped.');
        });
    }
}
