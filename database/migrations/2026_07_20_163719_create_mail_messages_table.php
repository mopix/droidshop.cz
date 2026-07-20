<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // The Mailable class, kept as a string: the log has to stay
            // readable after the class is renamed or removed.
            $table->string('mailable');
            $table->json('recipients');
            $table->string('subject');

            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('error')->nullable();

            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();

            // The emails_month counter reads this range on every limit check.
            $table->index(['tenant_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
