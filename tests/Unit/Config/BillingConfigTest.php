<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

class BillingConfigTest extends TestCase
{
    public function test_defaults(): void
    {
        $this->assertSame(14, config('billing.trial_days'));
        $this->assertSame(7, config('billing.grace_days'));
        $this->assertIsArray(config('billing.company'));
        $this->assertArrayHasKey('name', config('billing.company'));
        $this->assertSame('null', config('billing.subscription.driver'));
        $this->assertFalse(config('billing.monthly_charge_enabled'));
    }
}
