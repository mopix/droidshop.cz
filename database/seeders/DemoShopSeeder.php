<?php

namespace Database\Seeders;

use App\Core\Enums\TenantStatus;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;

/**
 * A ready-to-click demo shop for local review — a tenant on obchod.droidshop with
 * modules on, a handful of products, delivery and payment methods (including a
 * Comgate gateway in test mode). Idempotent: re-running updates rather than
 * duplicating. NOT for production — credentials are the well-known "password".
 *
 * Run order matters: migrate → modules:sync → db:seed --class=DemoShopSeeder.
 * modules:sync must have populated the module registry first, or activation
 * has nothing to grant.
 */
class DemoShopSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        PlatformAdmin::updateOrCreate(
            ['email' => 'super@droidshop.cz'],
            ['name' => 'Superadmin', 'password' => Hash::make('password')],
        );

        // Premium, so the demo shop runs every module the platform ships — the
        // marketing tools included. It deliberately does NOT hand-attach
        // modules to a tarif any more: PlanSeeder composes both tarifs from the
        // manifest levels (PlanModuleDefaults), so the demo mirrors production
        // instead of inventing a base plan that grants everything. That
        // divergence is how a core module ended up in plan_modules.
        $plan = Plan::where('key', 'premium')->firstOrFail();

        $tenant = Tenant::firstWhere('name', 'Demo obchod');

        if (! $tenant) {
            $owner = User::updateOrCreate(
                ['email' => 'admin@demo.cz'],
                ['name' => 'Majitel Demo', 'password' => Hash::make('password')],
            );

            $tenant = app(TenantProvisioner::class)->provision($owner, 'Demo obchod', 'obchod', $plan);
            $tenant->update([
                'status' => TenantStatus::Active,
                'mail_reply_to' => 'demo@droidshop.cz',
                // A VAT payer with a filled-in billing profile, so the demo
                // shows what most shops look like: prices with VAT, a rate on
                // every product, a recap in the checkout and a proper tax
                // document. A shop that is not registered is the narrower case
                // (wave 3.7) and would leave half the platform invisible in
                // the demo.
                'vat_payer' => true,
                'billing_name' => 'Demo obchod s.r.o.',
                'billing_ico' => '12345678',
                'billing_dic' => 'CZ12345678',
                'billing_address' => [
                    'street' => 'Demoluční 1',
                    'city' => 'Brno',
                    'zip' => '60200',
                    'country' => 'CZ',
                ],
            ]);
        }

        app(TenantContext::class)->runAs($tenant, fn () => $this->seedShop());
    }

    private function seedShop(): void
    {
        $rateId = app(TaxRates::class)->default()->id;
        $writer = app(ProductWriter::class);

        $products = [
            ['Mechanická klávesnice Droid K1', 'kdroid-k1', 'Hot-swap spínače, RGB podsvícení, hliníkové tělo.', 179000, 40, 850],
            ['Bezdrátová myš Droid M2', 'mysh-m2', 'Tichá tlačítka, 8000 DPI senzor, USB-C nabíjení.', 79000, 60, 95],
            ['USB-C dokovací stanice Droid D3', 'dok-d3', 'HDMI 4K, 3× USB-A, čtečka karet, 100W PD.', 249000, 15, 320],
            ['Sluchátka Droid H4', 'sluch-h4', 'ANC, 40h výdrž, Bluetooth 5.3.', 199000, 25, 260],
        ];

        foreach ($products as [$name, $sku, $desc, $price, $stock, $weight]) {
            if (Product::where('sku', $sku)->exists()) {
                continue;
            }

            $writer->create([
                'name' => $name,
                'sku' => $sku,
                'short_description' => $desc,
                'description' => '<p>'.$desc.'</p><p>Demo produkt e-shopu DroidShop.</p>',
                'price' => $price,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => $rateId,
                'weight_g' => $weight,
                'stock_tracked' => true,
                'stock_qty' => $stock,
            ]);
        }

        if (ShippingMethod::count() === 0) {
            ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr do 2 dnů',
                'price' => 9900,
                'tax_rate_id' => $rateId,
                'is_active' => true,
            ]);
            ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_PICKUP,
                'name' => 'Osobní odběr Praha',
                'price' => 0,
                'tax_rate_id' => $rateId,
                'is_active' => true,
            ]);
        }

        if (PaymentMethod::count() === 0) {
            PaymentMethod::create([
                'provider' => PaymentMethod::PROVIDER_COD,
                'name' => 'Dobírka',
                'fee' => 3000,
                'tax_rate_id' => $rateId,
                'is_active' => true,
                'position' => 10,
            ]);
            PaymentMethod::create([
                'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
                'name' => 'Bankovní převod (QR)',
                'fee' => 0,
                'tax_rate_id' => $rateId,
                'is_active' => true,
                'position' => 20,
                'settings' => ['account' => '2000145399/2010'],
            ]);
            PaymentMethod::create([
                'provider' => PaymentMethod::PROVIDER_COMGATE,
                'name' => 'Platební karta (Comgate)',
                'fee' => 0,
                'tax_rate_id' => $rateId,
                'is_active' => true,
                'position' => 30,
                // Test-mode placeholder credentials — no real charge is made.
                'settings' => ['merchant' => 'DEMO-MERCHANT', 'secret' => 'demo-secret', 'test' => true],
            ]);
        }
    }
}
