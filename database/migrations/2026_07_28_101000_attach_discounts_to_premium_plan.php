<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grants the premium plan the discounts module (spec §909 lists coupons as a
 * premium feature). A data migration rather than a seeder line, because
 * existing premium tenants must get it on deploy without anyone re-running a
 * seeder — the same shape as the wave 2.3 homepage backfill.
 *
 * Idempotent: nothing happens when the row is already there, and nothing
 * happens on a deploy whose registry has not been synced yet (modules:sync
 * runs before this in the deploy runbook, but a fresh install runs migrations
 * first — the module row simply does not exist yet, and ModulesSync will
 * create it. Re-running this migration is not needed then: PlanSeeder-driven
 * installs attach it through DemoShopSeeder, and production deploys run
 * modules:sync before migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        $plan = DB::table('plans')->where('key', 'premium')->first();
        $module = DB::table('modules')->where('key', 'discounts')->first();

        if ($plan === null || $module === null) {
            return;
        }

        $exists = DB::table('plan_modules')
            ->where('plan_id', $plan->id)
            ->where('module_key', 'discounts')
            ->exists();

        if (! $exists) {
            DB::table('plan_modules')->insert([
                'plan_id' => $plan->id,
                'module_key' => 'discounts',
            ]);
        }
    }

    public function down(): void
    {
        $plan = DB::table('plans')->where('key', 'premium')->first();

        if ($plan !== null) {
            DB::table('plan_modules')
                ->where('plan_id', $plan->id)
                ->where('module_key', 'discounts')
                ->delete();
        }
    }
};
