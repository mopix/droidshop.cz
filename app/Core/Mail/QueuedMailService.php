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

    public function send(Mailable $mailable, string|array $to, ?Tenant $tenant = null): MailMessage
    {
        $tenant ??= $this->context->current();

        if ($tenant === null) {
            throw new MissingTenantContext('E-mail nelze odeslat bez kontextu e-shopu.');
        }

        $verdict = $this->limits->check('emails_month');

        if (! $verdict->allowed()) {
            // Refused before the log row exists: a message we never sent must
            // not show up in the nájemce's outbox as if it had been.
            throw new MailLimitReached($verdict->message);
        }

        // An explicit $tenant must be authoritative, not just a label: the
        // MailMessage::creating hook (BelongsToTenant) stamps tenant_id from
        // the ambient TenantContext regardless of what we pass to create().
        // Running the rest of the method inside runAs() makes the ambient
        // context and the persisted tenant_id agree by construction, so a
        // caller sending "as" a different tenant than the current request
        // can never have the message logged against the wrong shop.
        return $this->context->runAs($tenant, function (Tenant $tenant) use ($mailable, $to): MailMessage {
            $recipients = array_values((array) $to);

            $mailable->from($this->sender->fromAddress(), $this->sender->fromName($tenant));

            if ($replyTo = $this->sender->replyTo($tenant)) {
                $mailable->replyTo($replyTo);
            }

            $message = MailMessage::create([
                'tenant_id' => $tenant->id,
                'mailable' => $mailable::class,
                'recipients' => $recipients,
                'subject' => $this->subjectOf($mailable),
                'status' => MailMessage::STATUS_QUEUED,
                'queued_at' => now(),
            ]);

            SendTenantMail::dispatch($message->id, $mailable);

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
