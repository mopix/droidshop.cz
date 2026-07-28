<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\Contracts\DiscountRedemption as DiscountRedemptionContract;
use App\Core\Discounts\Exceptions\DiscountNoLongerValid;
use App\Core\Money\Money;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountRedemption;

/**
 * Consumes and releases a discount's usage allowance.
 *
 * redeem() runs INSIDE OrderPlacer's transaction and takes a row lock on the
 * discount before it reads used_count: two shoppers racing for the last use of
 * a coupon must serialise here, exactly the way the stock decrement serialises
 * on its conditional UPDATE. The loser gets DiscountNoLongerValid and the
 * whole order rolls back — an allowance taken without an order, and an order
 * taking an allowance that was already gone, are both unacceptable.
 *
 * The evaluator (DiscountEligibility) checks the same two limits moments
 * earlier, unlocked, so a plainly exhausted coupon never gets this far. That
 * check decides what to SHOW; this one, under the lock, decides what is
 * actually consumed.
 *
 * The two limits are counted from different places on purpose: `usage_limit`
 * against `discounts.used_count` (one cheap counter, on the row already
 * locked) and `usage_limit_per_email` against live, unreleased
 * `discount_redemptions` rows — which leaves a release exactly one thing to
 * undo per limit: stamp the row released, and put the counter back.
 */
final class EloquentDiscountRedemption implements DiscountRedemptionContract
{
    public function redeem(int $discountId, int $orderId, string $email, ?int $customerId, Money $amount): void
    {
        // Tenant-scoped by BelongsToTenant, so a discount id belonging to
        // another tenant simply never resolves.
        $discount = Discount::query()->whereKey($discountId)->lockForUpdate()->first();

        if ($discount === null) {
            // Deleted between the evaluation and here. Nothing to consume, and
            // nothing to bill back: the order keeps the price it was already
            // given and its order_discounts snapshot still records what
            // happened. Refusing the whole order over a counter the shop
            // itself removed would punish the shopper for the shop's edit.
            return;
        }

        $email = mb_strtolower(trim($email));

        if ($discount->usage_limit !== null && (int) $discount->used_count >= (int) $discount->usage_limit) {
            throw DiscountNoLongerValid::forCode($discount->code);
        }

        // Also re-checked under the lock, not just by the evaluator: two
        // simultaneous submits from the same address both pass the unlocked
        // check (neither has written a row yet) and then serialise here, so
        // this is the only place that can actually hold the limit.
        if ($discount->usage_limit_per_email !== null
            && $this->liveRedemptions($discount, $email) >= (int) $discount->usage_limit_per_email) {
            throw DiscountNoLongerValid::forCode($discount->code);
        }

        // Unique on (tenant, discount, order): a retried submit that resolves
        // to the same order can never consume the allowance twice.
        DiscountRedemption::query()->create([
            'discount_id' => $discountId,
            'order_id' => $orderId,
            'email' => $email,
            'customer_id' => $customerId,
            'amount' => $amount->amount,
        ]);

        $discount->increment('used_count');
    }

    public function release(int $orderId): void
    {
        $rows = DiscountRedemption::query()
            ->where('order_id', $orderId)
            ->whereNull('released_at')
            ->get();

        foreach ($rows as $row) {
            // Stamped released rather than deleted: the row is the record of
            // what this order was given, and the per-e-mail limit counts only
            // unreleased rows — so the stamp is the whole undo. Filtering on
            // released_at above is also what makes a repeated release a no-op
            // (the contract promises idempotence).
            $row->update(['released_at' => now()]);

            // The counter has to come back down with it, or a released
            // redemption would hold a usage_limit slot forever. Guarded
            // against underflow: used_count is unsigned, so a counter already
            // at zero (hand-edited, or reset by an admin) must be left alone
            // rather than wrapped.
            Discount::query()
                ->whereKey($row->discount_id)
                ->where('used_count', '>', 0)
                ->decrement('used_count');
        }
    }

    private function liveRedemptions(Discount $discount, string $email): int
    {
        return $discount->redemptions()
            ->whereNull('released_at')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->count();
    }
}
