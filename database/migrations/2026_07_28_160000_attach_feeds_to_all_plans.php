<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grants every plan the feeds module.
 *
 * A base module, not a premium one: a Heureka feed is a condition of selling
 * in the Czech market, so hiding it behind a higher tariff would push the
 * entry plan below what competitors give as standard.
 *
 * A data migration rather than a seeder line, because existing tenants must
 * get it on deploy without anyone re-running a seeder — the same shape as the
 * wave 2.6 discounts backfill. Idempotent, and a no-op on a fresh install
 * whose registry has not been synced yet (modules:sync runs before migrate in
 * the deploy runbook; PlanSeeder attaches it for fresh installs).
 */
return new class extends Migration
{
    public function up(): void
    {
        $module = DB::table('modules')->where('key', 'feeds')->first();

        if ($module === null) {
            return;
        }

        foreach (DB::table('plans')->pluck('id') as $planId) {
            $exists = DB::table('plan_modules')
                ->where('plan_id', $planId)
                ->where('module_key', 'feeds')
                ->exists();

            if (! $exists) {
                DB::table('plan_modules')->insert([
                    'plan_id' => $planId,
                    'module_key' => 'feeds',
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('plan_modules')->where('module_key', 'feeds')->delete();
    }
};
