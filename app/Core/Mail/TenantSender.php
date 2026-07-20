<?php

namespace App\Core\Mail;

use App\Models\Tenant;

/**
 * Who outgoing mail claims to be from.
 *
 * The envelope address is always ours: SPF and DKIM are published for the
 * platform's domain, and sending as tenant@his-own-domain.cz from our servers
 * is exactly the shape spam filters reject. The tenant gets the display name
 * and the reply-to, which is what a customer actually reads and replies to.
 */
class TenantSender
{
    public function fromAddress(): string
    {
        return (string) config('mail.from.address');
    }

    public function fromName(Tenant $tenant): string
    {
        return $tenant->mail_from_name ?: $tenant->name;
    }

    public function replyTo(Tenant $tenant): ?string
    {
        return $tenant->mail_reply_to ?: null;
    }
}
