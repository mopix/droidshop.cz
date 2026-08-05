<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that a tenant agreed to the platform's terms.
 *
 * Both columns or neither: a timestamp without a version cannot answer which
 * wording was accepted, and a version without a timestamp cannot answer when.
 * Nullable because every user who registered before this migration has no
 * recorded consent, and back-filling one would be inventing evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable()->after('email_verified_at');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });
    }
};
