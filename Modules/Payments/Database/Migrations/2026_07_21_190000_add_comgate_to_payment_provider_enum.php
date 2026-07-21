<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The payments module adds 'comgate' to payment_methods.provider.
 *
 * The column is an enum created by the shipping module for the two offline
 * providers; an online gateway is a new value the shipping module has no
 * reason to know about, so the payments module — the reason the value exists —
 * carries the schema change. Shared table, migrates platform-wide like every
 * module migration (spec §15.5). Raw MODIFY because Laravel's schema builder
 * cannot alter an enum's value set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payment_methods MODIFY COLUMN provider ENUM('cod', 'bank_transfer', 'comgate') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE payment_methods MODIFY COLUMN provider ENUM('cod', 'bank_transfer') NOT NULL");
    }
};
