<?php

namespace Tests\Feature\Core;

use App\Core\Discounts\AppliedDiscount;
use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\DiscountContext;
use App\Core\Money\Money;
use Tests\TestCase;

/**
 * A deploy without the discounts module must still resolve the contract and
 * answer "no discount" — the same guest-safe default ShippingOptions and
 * PaymentGatewayRegistry keep.
 */
class DiscountNullBindingTest extends TestCase
{
    public function test_the_kernel_default_engine_answers_no_discount(): void
    {
        $engine = app(DiscountEngine::class);

        $applied = $engine->apply(new DiscountContext(
            lines: [],
            itemsTotal: new Money(0, 'CZK'),
            couponCode: 'ANYTHING',
            customerId: null,
            email: null,
            shippingCost: new Money(0, 'CZK'),
        ));

        $this->assertInstanceOf(AppliedDiscount::class, $applied);
        $this->assertSame([], $applied->perLine);
        $this->assertFalse($applied->freeShipping);
        $this->assertTrue($applied->total->isZero());
        $this->assertSame([], $applied->sources);
        $this->assertNull($applied->rejection);
    }
}
