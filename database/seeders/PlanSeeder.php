<?php

namespace Database\Seeders;

use App\Core\Enums\PlanLevel;
use App\Models\Module;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Prices are placeholders: the pricing decision is still open (spec §13).
     * Values are in haléře.
     */
    public function run(): void
    {
        Plan::updateOrCreate(['key' => 'base'], [
            'name' => 'Základní',
            'price_month' => 49900,
            'price_year' => 499000,
            'level' => PlanLevel::Base,
            'is_public' => true,
            'limits' => [
                'products' => 500,
                'storage_mb' => 2048,
                'emails_month' => 3000,
            ],
        ]);

        Plan::updateOrCreate(['key' => 'premium'], [
            'name' => 'Premium',
            'price_month' => 99900,
            'price_year' => 999000,
            'level' => PlanLevel::Premium,
            'is_public' => true,
            'limits' => [
                'products' => 5000,
                'storage_mb' => 20480,
                'emails_month' => 30000,
            ],
        ]);

        // Premium-only modules (spec §909). Attached here so a fresh install
        // and the test suite agree with the production backfill migration.
        $premium = Plan::where('key', 'premium')->first();

        if ($premium !== null && Module::where('key', 'discounts')->exists()) {
            $premium->modules()->syncWithoutDetaching(['discounts']);
        }

        // Feeds are base: a Heureka feed is a condition of selling in the Czech
        // market, not an upsell, so every plan gets it.
        if (Module::where('key', 'feeds')->exists()) {
            Plan::all()->each(fn (Plan $plan) => $plan->modules()->syncWithoutDetaching(['feeds']));
        }
    }
}
