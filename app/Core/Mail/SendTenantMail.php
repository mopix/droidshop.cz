<?php

namespace App\Core\Mail;

use App\Core\Tenancy\Exceptions\MissingTenantContext;
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
    ) {
        // Defer the push itself until the dispatching transaction really
        // commits. Every connection in config/queue.php has
        // 'after_commit' => false, and the next stage of the project sends
        // this job from inside a DB transaction (order creation →
        // confirmation e-mail). Without this, a worker could pick the job
        // up before the mail_messages row — and the order it confirms — is
        // committed, find nothing, and silently drop the customer's order
        // confirmation. Setting it here, on the job itself rather than the
        // queue connection, makes it hold no matter which call site
        // dispatches this job.
        //
        // Queueable declares $afterCommit as null by default; assigning it
        // here (rather than redeclaring the property with a type) is what
        // keeps this compatible with that trait — a typed redeclaration
        // conflicts with the trait's untyped one.
        $this->afterCommit();
    }

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

        if ($message->status === MailMessage::STATUS_SENT) {
            // Mail::to()->send() can succeed and still leave the job looking
            // failed to the queue, if the update() just below throws (lost
            // connection, deadlock, ...). The queue then retries, and
            // without this guard the customer would receive the same order
            // confirmation two or three times.
            Log::info('Skipped an already-delivered queued mail.', [
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

        if ($message->status !== MailMessage::STATUS_QUEUED) {
            // The queue can call failed() after handle() already delivered
            // the mail and marked it sent (e.g. a crash between the
            // successful send and the queue recording the attempt as done).
            // Overwriting a delivered message here would tell the nájemce
            // delivery failed for mail the customer actually received.
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
     * does happen to be present — it throws MissingTenantContext rather
     * than returning null when no tenant is current at all (BelongsToTenant
     * fails loudly by design), so that case is caught here and treated the
     * same as a plain miss. The fallback bypasses the scope, but the
     * isolation here does not come from a tenant check — a primary-key
     * lookup already identifies at most one row in the whole table, so
     * re-checking tenant_id against the same row just fetched by that key
     * is tautological and cannot exclude it or admit any other row. What
     * actually keeps this safe is that $this->messageId is never user
     * input — it is the id this job's own dispatch created and fixed at
     * that time — so a context mismatch here can only ever produce a safe
     * miss (null), never a foreign tenant's row.
     *
     * Making a tenant check genuinely load-bearing would mean threading the
     * dispatch-time tenant id onto this job's constructor and asserting
     * against that captured value instead of the row's own column. Left as
     * a deliberate follow-up, not an oversight.
     */
    private function resolveMessageAfterFailure(): ?MailMessage
    {
        try {
            if ($message = MailMessage::find($this->messageId)) {
                return $message;
            }
        } catch (MissingTenantContext) {
            // No tenant current at all — the common case here. Fall through
            // to the scope-bypassing lookup below.
        }

        return MailMessage::withoutGlobalScopes()->find($this->messageId);
    }
}
