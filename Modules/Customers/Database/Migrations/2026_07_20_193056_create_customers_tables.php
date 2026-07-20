<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->string('password');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone', 32)->nullable();

            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();

            // GDPR erasure anonymises in place rather than deleting: past
            // orders must keep pointing at a customer row that still exists.
            $table->timestamp('anonymised_at')->nullable();

            $table->timestamps();

            // Unique per shop, not globally: the same person may hold an
            // account at several shops on the platform, and those are
            // different identities that must never resolve to one another.
            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->enum('kind', ['billing', 'shipping']);

            $table->string('company')->nullable();
            $table->string('reg_no', 16)->nullable();
            $table->string('vat_no', 16)->nullable();

            $table->string('street');
            $table->string('city');
            $table->string('zip', 16);
            $table->char('country', 2)->default('CZ');

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'kind']);
        });

        Schema::create('customer_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->enum('purpose', ['password_reset', 'email_verification']);

            // The hash, never the token itself: a leaked database row must not
            // be usable to take over an account.
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            // One live token per purpose per address per shop. Issuing a new
            // one replaces the old, so an old link in an old e-mail stops
            // working the moment a fresh one is requested.
            $table->unique(['tenant_id', 'email', 'purpose']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tokens');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
