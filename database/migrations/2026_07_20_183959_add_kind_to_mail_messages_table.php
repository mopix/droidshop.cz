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
        Schema::table('mail_messages', function (Blueprint $table) {
            // No default, not nullable: every message must state up front
            // whether it is transactional or bulk, because that is exactly
            // what decides whether the emails_month cap can block it
            // (product decision 2026-07-20, see App\Core\Mail\MailKind).
            $table->enum('kind', ['transactional', 'bulk'])->after('mailable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
