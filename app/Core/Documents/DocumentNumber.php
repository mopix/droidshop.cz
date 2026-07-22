<?php

namespace App\Core\Documents;

/**
 * Formats a document number as {PREFIX}{YYYY}{NNNN} and derives the year-scoped
 * series key used with SequenceService.
 *
 * The year lives in the series key (not just the printed number) so the gap-free
 * counter resets every year: SequenceService keys a counter row per
 * (tenant_id, series), and "invoices:2026" is a different row from
 * "invoices:2027". Zero-padding is presentation only and never truncates — a
 * sequence wider than the pad prints in full, because a dropped digit would
 * collide two documents onto one number.
 */
final class DocumentNumber
{
    public static function seriesKey(string $base, int $year): string
    {
        return $base.':'.$year;
    }

    public static function format(string $prefix, int $year, int $sequence, int $pad): string
    {
        return $prefix.$year.str_pad((string) $sequence, $pad, '0', STR_PAD_LEFT);
    }
}
