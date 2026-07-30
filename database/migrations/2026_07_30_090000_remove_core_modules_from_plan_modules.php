<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Core modules have no business in plan_modules: they run in every shop
 * regardless of tarif (ModuleRegistry::enabledFor() always includes them and
 * guardPlan() exempts them), so a grant row grants nothing.
 *
 * It is not merely noise. Both PlanModuleReconciler and TenantPlanSwitcher
 * derive "what a plan may take away" from this table; a core key in it lands in
 * the deactivate set, where ModuleRegistry::deactivate() throws. Both callers
 * now subtract core keys defensively — this migration makes the data agree with
 * the invariant instead of relying on the guard alone.
 *
 * The rows come from DemoShopSeeder, which attached every deployed module to the
 * demo plan (fixed alongside this migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plan_modules')
            ->whereIn('module_key', DB::table('modules')->where('core', true)->pluck('key'))
            ->delete();
    }

    public function down(): void
    {
        // Deliberately irreversible: the rows granted nothing, and restoring
        // them would restore the defect. Nothing reads them.
    }
};
