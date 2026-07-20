<?php

namespace App\Core\Mail;

use App\Models\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one logged message.
 *
 * Deliberately not marked NotTenantAware: queues are tenant-aware by default
 * (config/multitenancy.php), so this job restores the tenant before it runs
 * and the global scope on MailMessage keeps working inside the worker.
 */
class SendTenantMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $messageId,
        private readonly Mailable $mailable,
    ) {}

    public function handle(): void
    {
        $message = MailMessage::find($this->messageId);

        if ($message === null) {
            // The tenant was deleted between queueing and delivery. Sending
            // now would mail on behalf of a shop that no longer exists.
            return;
        }

        try {
            Mail::to($message->recipients)->send($this->mailable);

            $message->update([
                'status' => MailMessage::STATUS_SENT,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            $message->update([
                'status' => MailMessage::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
