<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\AppliedDiscount;
use App\Core\Discounts\AppliedDiscountSource;
use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\DiscountContext;
use App\Core\Discounts\DiscountLine;
use App\Core\Discounts\DiscountRejection;
use App\Core\Money\Money;
use Illuminate\Support\Collection;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountTarget;
use Modules\Storefront\Support\ShopModules;

/**
 * Decides what a basket is entitled to — the module's implementation of the
 * kernel's DiscountEngine.
 *
 * Called three times per purchase and deliberately so: once when the cart
 * renders, once at the checkout recap, and once inside OrderPlacer's
 * transaction. Only the last one is binding. A coupon that expires between
 * screens therefore never charges the wrong amount; it simply stops applying,
 * exactly the way a moved catalogue price does (rozhodnutí 2026-07-28).
 */
final class DiscountEvaluator implements DiscountEngine
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly DiscountEligibility $eligibility,
        private readonly DiscountAllocator $allocator,
    ) {}

    public function apply(DiscountContext $context): AppliedDiscount
    {
        $currency = $context->itemsTotal->currency;

        // The deploy carries this class, but the tenant may not run the
        // module — the same runtime gate EloquentOrderBook keeps, rather than
        // a manifest dependency that would make discounts undeactivatable.
        if (! $this->modules->has('discounts')) {
            return AppliedDiscount::none($currency);
        }

        $rejection = null;
        $coupon = null;

        if ($context->couponCode !== null && trim($context->couponCode) !== '') {
            [$coupon, $rejection] = $this->resolveCoupon(trim($context->couponCode), $context);
        }

        $rules = $this->rules($coupon !== null);

        /** @var list<array{discount: Discount, amount: Money, lines: list<DiscountLine>}> $fired */
        $fired = [];
        $freeShipping = false;
        $sources = [];

        foreach ($this->ordered($coupon, $rules) as $discount) {
            $lines = $this->eligibleLines($discount, $context);

            if ($discount !== $coupon && $this->eligibility->check($discount, $context, $lines) !== null) {
                // A rule that does not hold simply does not fire; only a
                // shopper-typed coupon owes an explanation.
                continue;
            }

            if ($discount->type === Discount::TYPE_FREE_SHIPPING) {
                $freeShipping = true;
                $sources[] = new AppliedDiscountSource(
                    discountId: (int) $discount->id,
                    type: $discount->type,
                    code: $discount->code,
                    name: $discount->name,
                    amount: new Money(0, $currency),
                    freeShipping: true,
                );

                continue;
            }

            $amount = $this->amountFor($discount, $lines, $currency);

            if ($amount->isZero()) {
                continue;
            }

            $fired[] = ['discount' => $discount, 'amount' => $amount, 'lines' => $lines];
        }

        $fired = $this->capToBasket($fired, $context->itemsTotal);

        $perLine = [];

        foreach ($fired as $entry) {
            foreach ($this->allocator->allocate($entry['amount'], $entry['lines']) as $itemId => $share) {
                $perLine[$itemId] = isset($perLine[$itemId]) ? $perLine[$itemId]->plus($share) : $share;
            }

            $sources[] = new AppliedDiscountSource(
                discountId: (int) $entry['discount']->id,
                type: $entry['discount']->type,
                code: $entry['discount']->code,
                name: $entry['discount']->name,
                amount: $entry['amount'],
            );
        }

        $perLine = array_filter($perLine, static fn (Money $m): bool => ! $m->isZero());

        $total = array_reduce(
            $fired,
            static fn (Money $carry, array $entry): Money => $carry->plus($entry['amount']),
            new Money(0, $currency),
        );

        return new AppliedDiscount($perLine, $freeShipping, $total, array_values($sources), $rejection);
    }

    /**
     * @return array{0: ?Discount, 1: ?DiscountRejection}
     */
    private function resolveCoupon(string $code, DiscountContext $context): array
    {
        $coupon = Discount::query()
            ->whereNotNull('code')
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])
            ->first();

        if ($coupon === null) {
            return [null, new DiscountRejection($code, DiscountRejection::NOT_FOUND)];
        }

        $reason = $this->eligibility->check($coupon, $context, $this->eligibleLines($coupon, $context));

        if ($reason !== null) {
            return [null, new DiscountRejection($code, $reason)];
        }

        return [$coupon, null];
    }

    /**
     * @return Collection<int, Discount>
     */
    private function rules(bool $couponApplied): Collection
    {
        return Discount::query()
            ->whereNull('code')
            ->where('active', true)
            ->when($couponApplied, fn ($query) => $query->where('combinable', true))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Discount>  $rules
     * @return list<Discount>
     */
    private function ordered(?Discount $coupon, Collection $rules): array
    {
        return $coupon === null ? $rules->all() : [$coupon, ...$rules->all()];
    }

    /**
     * @return list<DiscountLine>
     */
    private function eligibleLines(Discount $discount, DiscountContext $context): array
    {
        if ($discount->scope === Discount::SCOPE_CART) {
            return $context->lines;
        }

        $type = $discount->scope === Discount::SCOPE_CATEGORIES
            ? DiscountTarget::TYPE_CATEGORY
            : DiscountTarget::TYPE_PRODUCT;

        $targets = $discount->targets()
            ->where('target_type', $type)
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_values(array_filter(
            $context->lines,
            static fn (DiscountLine $line): bool => $type === DiscountTarget::TYPE_PRODUCT
                ? in_array($line->productId, $targets, true)
                : array_intersect($line->categoryIds, $targets) !== [],
        ));
    }

    /**
     * @param  list<DiscountLine>  $lines
     */
    private function amountFor(Discount $discount, array $lines, string $currency): Money
    {
        $base = array_reduce(
            $lines,
            static fn (Money $carry, DiscountLine $line): Money => $carry->plus($line->lineTotal),
            new Money(0, $currency),
        );

        if ($discount->type === Discount::TYPE_PERCENT) {
            return new Money(intdiv($base->amount * (int) $discount->value, 1000), $currency);
        }

        // A fixed discount larger than what it applies to takes everything it
        // can and no more — a negative line total is not representable (the
        // columns are unsigned) and would be nonsense on an invoice anyway.
        return new Money(min((int) $discount->value, $base->amount), $currency);
    }

    /**
     * Trims the fired discounts so their sum never exceeds the basket.
     *
     * Two stacking discounts can each be valid on their own and still add up
     * past what the shopper is buying (a 90 % coupon plus a fixed 500 Kč
     * rule). The excess is shaved proportionally rather than by dropping a
     * whole discount, so both still show in the summary with the amount they
     * actually contributed.
     *
     * @param  list<array{discount: Discount, amount: Money, lines: list<DiscountLine>}>  $fired
     * @return list<array{discount: Discount, amount: Money, lines: list<DiscountLine>}>
     */
    private function capToBasket(array $fired, Money $itemsTotal): array
    {
        $sum = array_reduce(
            $fired,
            static fn (Money $carry, array $entry): Money => $carry->plus($entry['amount']),
            new Money(0, $itemsTotal->currency),
        );

        if (! $sum->greaterThan($itemsTotal)) {
            return $fired;
        }

        $ratios = array_map(static fn (array $entry): int => $entry['amount']->amount, $fired);
        $capped = $itemsTotal->allocateByRatios($ratios);

        foreach ($fired as $i => $entry) {
            $fired[$i]['amount'] = $capped[$i];
        }

        return array_values(array_filter($fired, static fn (array $entry): bool => ! $entry['amount']->isZero()));
    }
}
