<?php

namespace Tests\Feature\Core\Sequences;

use App\Core\Sequences\SequenceService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenceNumberTest extends TestCase
{
    use RefreshDatabase;

    private function bootTenant(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant);
    }

    public function test_next_number_returns_contiguous_integers_from_one(): void
    {
        $this->bootTenant();
        $seq = app(SequenceService::class);

        $this->assertSame(1, $seq->nextNumber('invoices:2026'));
        $this->assertSame(2, $seq->nextNumber('invoices:2026'));
        $this->assertSame(3, $seq->nextNumber('invoices:2026'));
    }

    public function test_year_scoped_keys_have_independent_counters(): void
    {
        $this->bootTenant();
        $seq = app(SequenceService::class);

        $this->assertSame(1, $seq->nextNumber('invoices:2026'));
        $this->assertSame(2, $seq->nextNumber('invoices:2026'));
        // New year = new series key = counter restarts at 1.
        $this->assertSame(1, $seq->nextNumber('invoices:2027'));
    }

    public function test_distinct_series_do_not_share_a_counter(): void
    {
        $this->bootTenant();
        $seq = app(SequenceService::class);

        $this->assertSame(1, $seq->nextNumber('invoices:2026'));
        $this->assertSame(1, $seq->nextNumber('credit_notes:2026'));
        $this->assertSame(1, $seq->nextNumber('proformas:2026'));
    }
}
