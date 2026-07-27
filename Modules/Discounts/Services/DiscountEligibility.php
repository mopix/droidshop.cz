<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\DiscountContext;
use App\Core\Discounts\DiscountLine;
use App\Core\Discounts\DiscountRejection;
use App\Core\Money\Money;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Discounts\Models\Discount;

/**
 * Every gate a discount has to pass before it is worth any money.
 *
 * Separate from the evaluator because a coupon and an automatic rule run the
 * exact same gauntlet — the only difference between them is that a rejected
 * coupon has to be reported back to the shopper, while a rejected rule simply
 * does not fire.
 */
final class DiscountEligibility
{
    public function __construct(
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  list<DiscountLine>  $eligibleLines
     * @return string|null a DiscountRejection reason, or null when the discount holds
     */
    public function check(Discount $discount, DiscountContext $context, array $eligibleLines): ?string
    {
        if (! $discount->active) {
            return DiscountRejection::INACTIVE;
        }

        $now = now();

        if ($discount->starts_at !== null && $now->lt($discount->starts_at)) {
            return DiscountRejection::NOT_STARTED;
        }

        if ($discount->ends_at !== null && $now->gt($discount->ends_at)) {
            return DiscountRejection::EXPIRED;
        }

        if ($discount->min_cart_total !== null
            && $context->itemsTotal->lessThan(new Money((int) $discount->min_cart_total, $context->itemsTotal->currency))) {
            return DiscountRejection::MIN_CART;
        }

        if ($eligibleLines === []) {
            return DiscountRejection::NO_ELIGIBLE_ITEMS;
        }

        if ($discount->requires_login && $context->customerId === null) {
            return DiscountRejection::REQUIRES_LOGIN;
        }

        $email = $context->email === null ? null : mb_strtolower(trim($context->email));

        if ($discount->first_order_only && $email !== null && $this->hasEarlierOrder($email)) {
            return DiscountRejection::FIRST_ORDER_ONLY;
        }

        if ($discount->usage_limit !== null && (int) $discount->used_count >= (int) $discount->usage_limit) {
            return DiscountRejection::USAGE_LIMIT;
        }

        if ($discount->usage_limit_per_email !== null && $email !== null
            && $this->redemptionsFor($discount, $email) >= (int) $discount->usage_limit_per_email) {
            return DiscountRejection::EMAIL_LIMIT;
        }

        return null;
    }

    /**
     * Read through the query builder rather than the orders module's model:
     * the discounts module must not import a class another module owns, and
     * a tenant that runs discounts without orders must still price a cart.
     *
     * `DB::table()` carries no BelongsToTenant scope of its own, so the
     * tenant filter below is load-bearing — dropping it would leak every
     * tenant's order history into every other tenant's first-order gate.
     */
    private function hasEarlierOrder(string $email): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('orders')) {
            return false;
        }

        return DB::table('orders')
            ->where('tenant_id', $this->tenants->id())
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();
    }

    private function redemptionsFor(Discount $discount, string $email): int
    {
        return $discount->redemptions()
            ->whereNull('released_at')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->count();
    }
}
