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
     * @return array{invitation: ReviewInvitation, token: string}
     */
    public function issue(int $orderId): array
    {
        $token = Str::random(48);

        $invitation = ReviewInvitation::query()->create([
            'order_id' => $orderId,
            'token_hash' => hash('sha256', $token),
            'sent_at' => now(),
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * Null for an unknown, expired or already used token — the caller must
     * not be able to tell those apart, or the page becomes an oracle for
     * guessing valid order ids.
     */
    public function resolve(string $token): ?ReviewInvitation
    {
        $invitation = ReviewInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($invitation === null || ! $invitation->isUsable()) {
            return null;
        }

        return $invitation;
    }
}
