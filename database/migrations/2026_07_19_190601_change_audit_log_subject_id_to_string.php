<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit subjects do not all have integer keys.
 *
 * Module is keyed by its manifest name, so logging "module.activated" against
 * an unsignedBigInteger column failed outright. Widening the column keeps the
 * audit log able to point at anything in the system, whatever its key type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->string('subject_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }
};
