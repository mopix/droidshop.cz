<?php

namespace App\Core\Mail;

use App\Core\Limits\LimitsService;
use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\Exceptions\MailLimitReached;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;

class QueuedMailService implements MailService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantSender $sender,
        private readonly LimitsService $limits,
    ) {}

    public function send(Mailable $mailable, string|array $to, MailKind $kind, ?Tenant $tenant = null): MailMessage
    {
        $tenant ??= $this->context->current();

        if ($tenant === null) {
            throw new MissingTenantContext('E-mail nelze odeslat bez kontextu e-shopu.');
        }

        // An explicit $tenant must be authoritative, not just a label: the
        // MailMessage::creating hook (BelongsToTenant) stamps tenant_id from
        // the ambient TenantContext regardless of what we pass to create().
        // Running the rest of the method inside runAs() makes the ambient
        // context and the persisted tenant_id agree by construction, so a
        // caller sending "as" a different tenant than the current request
        // can never have the message logged against the wrong shop.
        //
        // The limit check lives inside this closure too, not before it: it
        // must be evaluated against the same tenant the message is billed
        // to, not whatever tenant happened to be ambient when send() was
        // called. Checking outside runAs() let an ambient tenant's quota
        // gate an explicit tenant's send (or vice versa), and broke the
        // explicit-tenant-with-no-ambient-context path entirely.
        return $this->context->runAs($tenant, function (Tenant $tenant) use ($mailable, $to, $kind): MailMessage {
            // Transactional mail skips the cap entirely (product decision,
            // see MailKind's docblock): it still counts toward usage once
            // logged below, it just never gets refused.
            if ($kind === MailKind::Bulk) {
                $verdict = $this->limits->check('emails_month');

                if (! $verdict->allowed()) {
                    // Refused before the log row exists: a message we never sent must
                    // not show up in the nájemce's outbox as if it had been.
                    throw new MailLimitReached($verdict->message);
                }
            }

            $recipients = array_values((array) $to);

            // Clone before mutating: the caller may reuse one Mailable
            // instance across several tenants (e.g. a platform notice sent
            // in a loop). Mutating the original would leave the previous
            // tenant's display name and reply-to on it for the next one.
            $outgoing = clone $mailable;
            $outgoing->from($this->sender->fromAddress(), $this->sender->fromName($tenant));

            if ($replyTo = $this->sender->replyTo($tenant)) {
                $outgoing->replyTo($replyTo);
            }

            $message = MailMessage::create([
                'tenant_id' => $tenant->id,
                'mailable' => $outgoing::class,
                'recipients' => $recipients,
                'subject' => $this->subjectOf($outgoing),
                'kind' => $kind,
                'status' => MailMessage::STATUS_QUEUED,
                'queued_at' => now(),
            ]);

            SendTenantMail::dispatch($message->id, $outgoing);

            return $message;
        });
    }

    /**
     * A mailable declares its subject either through envelope() or inside
     * build(), and neither has run yet at queue time.
     *
     * build() runs on a clone: on the real instance it would append the
     * attachments and addresses a second time when the job delivers it.
     */
    private function subjectOf(Mailable $mailable): string
    {
        if (method_exists($mailable, 'envelope')) {
            return $mailable->envelope()->subject ?? class_basename($mailable);
        }

        $probe = clone $mailable;
        $probe->build();

        return $probe->subject ?? class_basename($mailable);
    }
}
