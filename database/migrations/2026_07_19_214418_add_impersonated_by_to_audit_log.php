<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which superadmin was impersonating when an action was logged
 * (spec §15.4). Nullable: almost every action is taken by the user themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('impersonated_by')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropColumn('impersonated_by');
        });
    }
};
