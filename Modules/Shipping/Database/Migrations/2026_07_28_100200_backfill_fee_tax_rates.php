<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing fees of VAT-paying shops get the shop's default rate.
        // Until now they were charged but silently dropped from the
        // recapitulation, so an invoice total did not match its own tax rows.
        foreach (DB::table('tenants')->where('vat_payer', true)->pluck('id') as $tenantId) {
            $default = DB::table('tax_rates')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if ($default === null) {
                continue;
            }

            foreach (['shipping_methods', 'payment_methods'] as $table) {
                DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->whereNull('tax_rate_id')
                    ->update(['tax_rate_id' => $default]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible on purpose: which fees carried no rate before is not
        // recorded anywhere, and guessing would put the data back wrong.
    }
};
