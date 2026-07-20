<?php

namespace App\Core\Mail;

use App\Core\Limits\Contracts\LimitCounter;
use App\Models\MailMessage;
use App\Models\Tenant;

/**
 * How many messages the tenant has committed to sending this calendar month
 * (spec §15.1).
 *
 * Counts messages that are STATUS_QUEUED or STATUS_SENT, not only delivered
 * ones: the cap must reflect what the tenant has committed (spec decision
 * 2026-07-20), otherwise a burst can enqueue arbitrarily far past the cap
 * before a worker delivers a single one of them. STATUS_FAILED is excluded:
 * a message that failed to send cost the tenant nothing and must not eat
 * their allowance.
 *
 * The month filter keys off queued_at, not sent_at: a queued message has
 * sent_at = null (it hasn't been delivered yet), so filtering on sent_at
 * would silently drop every still-queued row from the count.
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
            ->whereIn('status', [MailMessage::STATUS_QUEUED, MailMessage::STATUS_SENT])
            ->where('queued_at', '>=', now()->startOfMonth())
            ->count();
    }
}
