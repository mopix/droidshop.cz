<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings that belong to the shop as a whole (wave 3.6).
 *
 * A table of its own rather than more columns on `tenants`, more columns on
 * `tenant_theme`, or rows in `settings`:
 *
 * - `tenants` is the platform's record of a customer — who they are, what
 *   they pay, whether they are suspended. How their shop presents itself is
 *   not that.
 * - `tenant_theme` is branding, read on every storefront request by a view
 *   composer. Widening it would drag a dozen columns nobody is looking at
 *   into that read.
 * - `settings` (wave 2.10) is keyed BY MODULE and validated against that
 *   module's manifest. Nothing here belongs to a module — somebody would
 *   have to decide which module "owns" the shop's time zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();

            // One row per tenant. The unique index doubles as the
            // tenant_id-leading index the schema-convention test requires.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->unique();

            // Shop
            $table->string('tagline')->nullable();
            $table->string('timezone', 64)->default('Europe/Prague');
            $table->string('date_format', 32)->default('j. n. Y');
            $table->string('time_format', 32)->default('H:i');

            // Contacts
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 64)->nullable();
            $table->string('contact_street')->nullable();
            $table->string('contact_city')->nullable();
            $table->string('contact_zip', 32)->nullable();
            $table->string('contact_country', 2)->nullable();
            $table->string('opening_hours')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('x_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('tiktok_url')->nullable();

            // SEO
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('og_image_path')->nullable();
            $table->boolean('noindex')->default(false);

            // Display
            $table->boolean('hide_empty_categories')->default(false);
            $table->string('empty_search_text')->nullable();
            $table->boolean('show_footer_contact')->default(true);

            // Lock. The password is stored hashed — see ShopSettings::casts().
            $table->boolean('locked')->default(false);
            $table->string('lock_password')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
