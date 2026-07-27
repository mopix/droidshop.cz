<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puts the packeta module in every plan (wave 2.5).
 *
 * Delivery is a baseline shop function, not an upsell — it would belong behind
 * a paywall only if it cost us something. Idempotent so a re-run is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('modules')->where('key', 'packeta')->exists()) {
            // modules:sync has not run yet on this environment; the deploy
            // runbook runs it before migrations, but never assume.
            return;
        }

        foreach (DB::table('plans')->pluck('id') as $planId) {
            $exists = DB::table('plan_modules')
                ->where('plan_id', $planId)
                ->where('module_key', 'packeta')
                ->exists();

            if (! $exists) {
                DB::table('plan_modules')->insert(['plan_id' => $planId, 'module_key' => 'packeta']);
            }
        }
    }

    public function down(): void
    {
        DB::table('plan_modules')->where('module_key', 'packeta')->delete();
    }
};
