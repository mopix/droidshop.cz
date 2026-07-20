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
            // emails_month now counts queued messages as well as sent ones
            // (MailLimitCounter), and a queued message has sent_at = null —
            // so that counter's month filter keys off queued_at instead,
            // which is populated for every row regardless of status.
            $table->index(['tenant_id', 'queued_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'queued_at']);
        });
    }
};
