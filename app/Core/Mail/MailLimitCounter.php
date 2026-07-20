<?php

namespace App\Core\Mail;

use App\Core\Limits\Contracts\LimitCounter;
use App\Models\MailMessage;
use App\Models\Tenant;

/**
 * How many messages the tenant has sent this calendar month (spec §15.1).
 *
 * Counts delivered messages, not queued ones: a message that failed to send
 * cost the tenant nothing and must not eat their allowance.
 */
class MailLimitCounter implements LimitCounter
{
    public function limit(): string
    {
        return 'emails_month';
    }

    public function count(Tenant $tenant): int
    {
        return MailMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', MailMessage::STATUS_SENT)
            ->where('sent_at', '>=', now()->startOfMonth())
            ->count();
    }
}
