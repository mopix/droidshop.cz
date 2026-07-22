<?php

namespace Tests\Feature\Billing;

use App\Core\Billing\PlatformSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_is_gap_free_and_per_series(): void
    {
        $svc = app(PlatformSequenceService::class);

        $this->assertSame(1, $svc->nextNumber('platform_invoices:2026'));
        $this->assertSame(2, $svc->nextNumber('platform_invoices:2026'));
        $this->assertSame(1, $svc->nextNumber('platform_invoices:2027')); // different series resets
    }
}
