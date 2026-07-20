<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();

            // Stable identifier for code, imports and feeds. The percentage
            // changes when the legislator says so; "standard" does not.
            $table->string('code', 32)->unique();
            $table->string('name');

            // Per mille as an integer: 21 % is 210. Percent alone cannot hold
            // a rate like 12.5 %, and a float on a tax rate is how rounding
            // error reaches an invoice.
            $table->unsignedSmallInteger('rate_permille');

            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();
        });

        // Seeded in the migration, not a seeder: no shop can operate without
        // these, and a fresh deploy must not depend on someone remembering.
        $now = now();

        DB::table('tax_rates')->insert([
            ['code' => 'standard', 'name' => 'Základní 21 %', 'rate_permille' => 210, 'is_default' => true, 'position' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'reduced', 'name' => 'Snížená 12 %', 'rate_permille' => 120, 'is_default' => false, 'position' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'zero', 'name' => 'Nulová 0 %', 'rate_permille' => 0, 'is_default' => false, 'position' => 30, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
