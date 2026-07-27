<?php

namespace Tests\Feature\Modules\Discounts;

use App\Core\Discounts\DiscountLine;
use App\Core\Money\Money;
use Modules\Discounts\Services\DiscountAllocator;
use Tests\TestCase;

class DiscountAllocatorTest extends TestCase
{
    private function line(int $itemId, int $lineTotal): DiscountLine
    {
        return new DiscountLine(
            itemId: $itemId,
            productId: $itemId * 10,
            variantId: null,
            categoryIds: [],
            lineTotal: new Money($lineTotal, 'CZK'),
            taxRatePercent: 21.0,
        );
    }

    public function test_the_allocation_sums_exactly_to_the_discount(): void
    {
        $allocator = new DiscountAllocator;

        // 100 Kč split across three lines that do not divide evenly.
        $allocation = $allocator->allocate(
            new Money(10000, 'CZK'),
            [$this->line(1, 3333), $this->line(2, 3333), $this->line(3, 3334)],
        );

        $sum = array_sum(array_map(fn (Money $m): int => $m->amount, $allocation));

        $this->assertSame(10000, $sum);
        $this->assertSame([1, 2, 3], array_keys($allocation));
    }

    public function test_the_allocation_follows_line_totals_not_line_count(): void
    {
        $allocator = new DiscountAllocator;

        $allocation = $allocator->allocate(
            new Money(1000, 'CZK'),
            [$this->line(1, 9000), $this->line(2, 1000)],
        );

        $this->assertSame(900, $allocation[1]->amount);
        $this->assertSame(100, $allocation[2]->amount);
    }

    public function test_nothing_is_allocated_without_eligible_lines(): void
    {
        $allocator = new DiscountAllocator;

        $this->assertSame([], $allocator->allocate(new Money(1000, 'CZK'), []));
    }

    public function test_a_zero_valued_basket_allocates_nothing(): void
    {
        $allocator = new DiscountAllocator;

        $allocation = $allocator->allocate(new Money(1000, 'CZK'), [$this->line(1, 0)]);

        // Documented exception: when every line is worth nothing, the allocation
        // returns zeros (sum is 0, not $amount) because there is no proportion
        // to follow. This is unreachable in practice since the discount amount
        // is derived from these same line totals.
        $this->assertSame([1], array_keys($allocation));
        $this->assertSame(0, $allocation[1]->amount);
        $sum = array_sum(array_map(fn (Money $m): int => $m->amount, $allocation));
        $this->assertSame(0, $sum);
    }
}
