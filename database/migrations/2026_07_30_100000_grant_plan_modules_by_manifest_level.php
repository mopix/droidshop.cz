<?php

use App\Core\Modules\PlanModuleDefaults;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfills `plan_modules` from each module's manifest level (2026-07-30).
 *
 * A module no plan grants cannot be switched on at all
 * (`PlanDoesNotIncludeModule`), and nothing ever put the ordinary modules into
 * the table: only `discounts` and `feeds` had their own attach migrations, so a
 * production base plan granted those two and nothing else. An onboarded shop
 * came up without a catalogue or a checkout.
 *
 * Tarif split (owner's decision, 2026-07-30): base = the whole selling e-shop,
 * premium = base + the marketing tools (today `discounts`) and the higher
 * limits. That is exactly what the manifest `level` already says, so this only
 * makes the data agree with it.
 *
 * Attaches only; never detaches. A tarif composed by hand on the wave 2.10
 * superadmin screen keeps every grant it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PlanModuleDefaults::class)->apply();
    }

    public function down(): void
    {
        // Deliberately a no-op: reverting would take modules away from live
        // shops on the next reconciliation. Composing a tarif differently is a
        // superadmin action, not a rollback.
    }
};
