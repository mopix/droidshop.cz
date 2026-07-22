<?php

namespace Tests\Unit\Core\Documents;

use App\Core\Documents\DocumentNumber;
use PHPUnit\Framework\TestCase;

class DocumentNumberTest extends TestCase
{
    public function test_series_key_embeds_the_year(): void
    {
        $this->assertSame('invoices:2026', DocumentNumber::seriesKey('invoices', 2026));
    }

    public function test_format_pads_sequence_and_joins_prefix_year(): void
    {
        $this->assertSame('FV20260001', DocumentNumber::format('FV', 2026, 1, 4));
        $this->assertSame('FV20260042', DocumentNumber::format('FV', 2026, 42, 4));
    }

    public function test_format_does_not_truncate_sequence_wider_than_pad(): void
    {
        $this->assertSame('FV202612345', DocumentNumber::format('FV', 2026, 12345, 4));
    }

    public function test_empty_prefix_is_allowed(): void
    {
        $this->assertSame('20260001', DocumentNumber::format('', 2026, 1, 4));
    }
}
