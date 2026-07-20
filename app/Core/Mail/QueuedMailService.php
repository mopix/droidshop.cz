<?php

namespace App\Core\Mail;

use App\Core\Mail\Contracts\MailService;
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
    ) {}

    public function send(Mailable $mailable, string|array $to, ?Tenant $tenant = null): MailMessage
    {
        $tenant ??= $this->context->current();

        if ($tenant === null) {
            throw new MissingTenantContext('E-mail nelze odeslat bez kontextu e-shopu.');
        }

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
