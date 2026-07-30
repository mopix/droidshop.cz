<?php

namespace Database\Seeders;

use App\Core\Enums\PlanLevel;
use App\Core\Modules\PlanModuleDefaults;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function __construct(private readonly PlanModuleDefaults $defaults) {}

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

        // Which tarif grants which module follows the manifest `level`, in one
        // place, for every deployed module — see PlanModuleDefaults. Before
        // this, each module needed to remember its own attach migration and a
        // fresh install ended up with a base plan granting almost nothing, so
        // an onboarded shop had no catalogue and no checkout.
        //
        // Base = the whole selling e-shop (catalogue, checkout, orders,
        // delivery, payments, customers, pages, invoices, Heureka feed,
        // Zásilkovna); premium adds the marketing tools on top (today
        // `discounts`) plus the higher limits set above.
        $this->defaults->apply();
    }
}
