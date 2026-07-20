<?php

namespace App\Core\Mail;

use App\Models\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one logged message.
 *
 * Deliberately not marked NotTenantAware: queues are tenant-aware by default
 * (config/multitenancy.php), so this job restores the tenant before it runs
 * and the global scope on MailMessage keeps working inside the worker.
 *
 * That restoration wraps handle() only — it is applied by the queue's "call
 * the job" pipeline (Illuminate\Queue\CallQueuedHandler::call()). failed()
 * runs through a separate path (CallQueuedHandler::failed()) that is not
 * covered by it, so it cannot rely on the ambient tenant being set. See
 * resolveMessageAfterFailure().
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
            Log::warning('Dropped queued mail: MailMessage no longer exists.', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        try {
            Mail::to($message->recipients)->send($this->mailable);

            $message->update([
                'status' => MailMessage::STATUS_SENT,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            // This attempt failed, but the queue may still retry it (up to
            // $tries): status stays "queued" so a nájemce checking on an
            // order confirmation is not told delivery failed while a retry
            // is still on its way. The error is recorded regardless, for
            // diagnostics. Only failed() — invoked once Laravel has actually
            // given up on the job, on every queue driver including sync —
            // writes STATUS_FAILED.
            $message->update(['error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Invoked by the queue once it has stopped retrying. This is the only
     * place that writes STATUS_FAILED.
     *
     * Reachable on every driver: Illuminate\Queue\SyncQueue has no real
     * retry loop, so it calls this after the single attempt it ever gives a
     * job, which is exactly why attempts()-based "is this the last try?"
     * logic never worked there (SyncJob::attempts() is hard-coded to 1).
     */
    public function failed(Throwable $e): void
    {
        $message = $this->resolveMessageAfterFailure();

        if ($message === null) {
            // The tenant was deleted between queueing and delivery.
            Log::warning('Dropped queued mail: MailMessage no longer exists.', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $message->update([
            'status' => MailMessage::STATUS_FAILED,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * failed() is resolved by the queue from a fresh deserialization of the
     * job payload captured at dispatch time, outside the pipeline that
     * restores the current tenant for handle(). In practice Tenant::current()
     * is unset by the time this runs, so the ordinary scoped lookup below
     * comes back empty even though the row exists — the BelongsToTenant
     * global scope hides it.
     *
     * The scoped lookup is tried first regardless, in case tenant context
     * does happen to be present. The fallback bypasses the scope, but stays
     * exactly as narrow as a scoped lookup would have been: it reads the row
     * by its own primary key and then re-asserts the match against that same
     * row's own tenant_id, so this can never be widened by accident into a
     * query that reaches another tenant's data. $this->messageId is never
     * user input — it is the id this job's own dispatch created — so there
     * is no untrusted value here that a scope would otherwise be guarding
     * against.
     */
    private function resolveMessageAfterFailure(): ?MailMessage
    {
        if ($message = MailMessage::find($this->messageId)) {
            return $message;
        }

        $row = MailMessage::withoutGlobalScopes()->find($this->messageId);

        if ($row === null) {
            return null;
        }

        return MailMessage::withoutGlobalScopes()
            ->where('id', $row->id)
            ->where('tenant_id', $row->tenant_id)
            ->first();
    }
}
