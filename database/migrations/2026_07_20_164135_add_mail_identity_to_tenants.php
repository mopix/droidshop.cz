<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Display name on outgoing mail. Null means "use tenants.name".
            $table->string('mail_from_name')->nullable()->after('name');
            // Where a customer's reply goes. The envelope sender stays ours.
            $table->string('mail_reply_to')->nullable()->after('mail_from_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['mail_from_name', 'mail_reply_to']);
        });
    }
};
