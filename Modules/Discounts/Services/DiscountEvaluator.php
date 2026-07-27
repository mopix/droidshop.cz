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
        $couponLines = null;

        if ($context->couponCode !== null && trim($context->couponCode) !== '') {
            [$coupon, $couponLines, $rejection] = $this->resolveCoupon(trim($context->couponCode), $context);
        }

        $rules = $this->rules($coupon !== null);

        /** @var list<array{discount: Discount, amount: Money, lines: list<DiscountLine>}> $fired */
        $fired = [];
        $freeShipping = false;
        $sources = [];

        foreach ($this->ordered($coupon, $rules) as $discount) {
            // The coupon's eligible lines were already resolved (and spent a
            // discount_targets query) inside resolveCoupon() to run its
            // eligibility check — reuse them instead of asking again. This
            // matters because apply() runs three times per purchase (see
            // class docblock).
            $lines = $discount === $coupon ? $couponLines : $this->eligibleLines($discount, $context);

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
                // A coupon the shopper typed still owes an answer — "applied,
                // for nothing" — even when its own math comes out to zero
                // (e.g. a 0 %-off code). An automatic rule that computes
                // zero simply never fired; no one is owed an explanation.
                if ($discount === $coupon) {
                    $sources[] = new AppliedDiscountSource(
                        discountId: (int) $discount->id,
                        type: $discount->type,
                        code: $discount->code,
                        name: $discount->name,
                        amount: $amount,
                    );
                }

                continue;
            }

            $fired[] = ['discount' => $discount, 'amount' => $amount, 'lines' => $lines];
        }

        $fired = $this->capToBasket($fired, $context->itemsTotal);

        [$perLine, $total, $allocatedSources] = $this->allocate($fired, $context->lines, $currency);

        $perLine = array_filter($perLine, static fn (Money $m): bool => ! $m->isZero());

        return new AppliedDiscount(
            $perLine,
            $freeShipping,
            $total,
            array_values([...$sources, ...$allocatedSources]),
            $rejection,
        );
    }

    /**
     * Allocates every fired discount across the lines it actually has room
     * on, capacity-aware.
     *
     * capToBasket() only bounds the SUM of what fires against the whole
     * basket (rule 6/8) — nothing upstream of this stops two discounts that
     * each target the same line from together putting more onto that one
     * line than it is worth (rule 5 talks about each discount's OWN base,
     * not what a line has left after an earlier discount already took a
     * bite). A negative line total is not just wrong money: order_items'
     * columns are unsigned, so it is a write failure downstream (Task 5/8).
     *
     * `$remaining` starts at each line's full total and is spent down as
     * discounts fire, in the exact order they were queued (coupon first,
     * then rules by `priority` then `id` — see ordered()/rules()). This is
     * also what makes `priority` observable for the first time: the
     * earlier-ordered discount gets first claim on a line's capacity, a
     * later one is clamped to whatever is left.
     *
     * Lines already at zero remaining are dropped from the ratio set before
     * calling the allocator, not just left in with a zero weight: Money::
     * allocateByRatios() hands its rounding remainder to buckets by index,
     * not by weight, so a zero-weight bucket left in the set could still
     * receive +1 from rounding and go negative-capacity by one haléř. A
     * bucket that is not in the set at all cannot receive anything.
     *
     * @param  list<array{discount: Discount, amount: Money, lines: list<DiscountLine>}>  $fired
     * @param  list<DiscountLine>  $allLines
     * @return array{0: array<int, Money>, 1: Money, 2: list<AppliedDiscountSource>}
     */
    private function allocate(array $fired, array $allLines, string $currency): array
    {
        $remaining = [];

        foreach ($allLines as $line) {
            $remaining[$line->itemId] = $line->lineTotal->amount;
        }

        $perLine = [];
        $total = new Money(0, $currency);
        $sources = [];

        foreach ($fired as $entry) {
            $available = array_values(array_filter(
                $entry['lines'],
                static fn (DiscountLine $line): bool => ($remaining[$line->itemId] ?? 0) > 0,
            ));

            $capacity = array_sum(array_map(
                static fn (DiscountLine $line): int => $remaining[$line->itemId],
                $available,
            ));

            $amount = $entry['amount']->amount > $capacity
                ? new Money($capacity, $currency)
                : $entry['amount'];

            if ($amount->isZero()) {
                // Every line this discount could touch is already spoken for
                // by an earlier (higher-priority, or coupon) discount.
                continue;
            }

            $capacityLines = array_map(
                static fn (DiscountLine $line): DiscountLine => new DiscountLine(
                    $line->itemId,
                    $line->productId,
                    $line->variantId,
                    $line->categoryIds,
                    new Money($remaining[$line->itemId], $currency),
                    $line->taxRatePercent,
                ),
                $available,
            );

            $actual = new Money(0, $currency);

            foreach ($this->allocator->allocate($amount, $capacityLines) as $itemId => $share) {
                $perLine[$itemId] = isset($perLine[$itemId]) ? $perLine[$itemId]->plus($share) : $share;
                $remaining[$itemId] -= $share->amount;
                $actual = $actual->plus($share);
            }

            $sources[] = new AppliedDiscountSource(
                discountId: (int) $entry['discount']->id,
                type: $entry['discount']->type,
                code: $entry['discount']->code,
                name: $entry['discount']->name,
                amount: $actual,
            );

            $total = $total->plus($actual);
        }

        return [$perLine, $total, $sources];
    }

    /**
     * @return array{0: ?Discount, 1: ?list<DiscountLine>, 2: ?DiscountRejection}
     */
    private function resolveCoupon(string $code, DiscountContext $context): array
    {
        $coupon = Discount::query()
            ->whereNotNull('code')
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])
            ->first();

        if ($coupon === null) {
            return [null, null, new DiscountRejection($code, DiscountRejection::NOT_FOUND)];
        }

        $lines = $this->eligibleLines($coupon, $context);
        $reason = $this->eligibility->check($coupon, $context, $lines);

        if ($reason !== null) {
            return [null, null, new DiscountRejection($code, $reason)];
        }

        return [$coupon, $lines, null];
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
