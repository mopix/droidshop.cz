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
        // Deliberately separate from users (spec §15.4): a breach of the
        // tenant-facing users table must not reach platform administration, and
        // vice versa. No tenant_id — this is a platform table, not tenant data.
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            // 2FA is mandatory (§15.4), but an admin exists before confirming
            // it, so the secret and confirmation are nullable until then.
            $table->text('two_fa_secret')->nullable();
            $table->timestamp('two_fa_confirmed_at')->nullable();
            $table->text('two_fa_recovery_codes')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
