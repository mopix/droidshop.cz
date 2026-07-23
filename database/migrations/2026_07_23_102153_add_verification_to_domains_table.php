<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // DNS TXT challenge token issued while verifying ownership of a
            // custom domain, before we ever attempt to obtain a certificate.
            $table->string('challenge_token')->nullable();

            // Last verification failure reason, shown to the tenant so a
            // stuck domain isn't a silent dead end.
            $table->string('verification_error')->nullable();

            // Last time we probed DNS/TLS state for this domain.
            $table->timestamp('last_checked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['challenge_token', 'verification_error', 'last_checked_at']);
        });
    }
};
