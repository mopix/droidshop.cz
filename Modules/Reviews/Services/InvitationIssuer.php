<?php

namespace Modules\Reviews\Services;

use Illuminate\Support\Str;
use Modules\Reviews\Models\ReviewInvitation;

/**
 * Issues and resolves the single-use link that lets a buyer write a review.
 *
 * A token rather than a login, because most orders on this platform are
 * placed as a guest: gating reviews behind an account would silently exclude
 * the majority of genuine buyers, which is the opposite of what verified
 * purchase is for.
 */
class InvitationIssuer
{
    /** Two months is long enough for a holiday and short enough to matter. */
    private const VALID_DAYS = 60;

    /**
     * The raw token exists only here and in the e-mail. The row keeps a hash,
     * so a leaked database dump is not a set of usable links.
     *
     * No $email argument: `review_invitations` carries no e-mail column
     * (see the wave-3 migration) — the recipient is always looked up from
     * the order itself, so a second copy here would be one more place for
     * the two to drift apart.
     *
     * `sent_at` is written null, not now(): issuing the row and delivering
     * the mail are two different facts. MailService::send() can still throw
     * after this row exists, and a row that claims a mail went out when it
     * never did would mislead the Task 5 admin screen. The caller stamps
     * sent_at only once send() actually returns.
     *
     * updateOrCreate() rather than create(): a previous call for the same
     * order may already have written a row that was never actually sent
     * (issue() ran, then send() failed for a reason other than
     * MailLimitReached). unique(tenant_id, order_id) would reject a second
     * create() outright, and SendReviewInvitations::deliveredOrdersDue()
     * deliberately keeps offering such an order up for retry (it excludes
     * only invitations with a non-null sent_at) — so this has to be able to
     * reuse that row rather than fail. The old token_hash is overwritten
     * with a fresh one: rotating it is correct, not a compromise, because
     * the previous token was never delivered to anybody.
     *
     * @return array{invitation: ReviewInvitation, token: string}
     */
    public function issue(int $orderId): array
    {
        $token = Str::random(48);

        $invitation = ReviewInvitation::query()->updateOrCreate(
            ['order_id' => $orderId],
            [
                'token_hash' => hash('sha256', $token),
                'sent_at' => null,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ]
        );

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * Null for an unknown, expired or already used token — the caller must
     * not be able to tell those apart, or the page becomes an oracle for
     * guessing valid order ids.
     */
    public function resolve(string $token): ?ReviewInvitation
    {
        $invitation = $this->find($token);

        if ($invitation === null || ! $invitation->isUsable()) {
            return null;
        }

        return $invitation;
    }

    /**
     * Same lookup as resolve(), but without the isUsable() gate.
     *
     * The unsubscribe link the invitation e-mail carries has to keep working
     * after the buyer has already written their review, and after the
     * 60-day expiry — an unsubscribe link that dies is not an unsubscribe
     * link, and bulk mail is required to carry one that works. Only the
     * form and submission routes need resolve()'s stricter gate.
     */
    public function resolveAny(string $token): ?ReviewInvitation
    {
        return $this->find($token);
    }

    private function find(string $token): ?ReviewInvitation
    {
        return ReviewInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }
}
