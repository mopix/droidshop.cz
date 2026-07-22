<?php

namespace Modules\Docs\Support;

use App\Core\Money\Money;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Throwable;

/**
 * Builds a SPAYD string (Short Payment Descriptor, the Czech QR-payment
 * standard) for an unpaid invoice, and renders it to a PNG data URI dompdf
 * can embed via a plain `<img>` tag.
 *
 * Deliberately its own SPAYD builder, not a reuse of
 * `Modules\Checkout\Support\Spayd`: a module never imports another module's
 * class (CLAUDE.md modular architecture rule), and the two payloads differ
 * anyway — a document's variable symbol is the order number recorded on its
 * own immutable snapshot, not something checkout still has a live cart for.
 */
final class InvoiceQr
{
    /**
     * @param  string  $account  The pay-to account/IBAN, read live from the
     *                           payment method (never stored on the document
     *                           snapshot — spec §16.5).
     * @param  Money  $amount  The invoice total, in minor units.
     * @param  string  $variableSymbol  The order number (digits only).
     */
    public static function spayd(string $account, Money $amount, string $variableSymbol): string
    {
        $fields = [
            'ACC:'.self::sanitizeAccount($account),
            'AM:'.self::amount($amount),
            'CC:'.strtoupper($amount->currency),
            'X-VS:'.self::variableSymbol($variableSymbol),
        ];

        return 'SPD*1.0*'.implode('*', $fields);
    }

    /**
     * A PNG data URI for the given SPAYD string, or null.
     *
     * Best-effort by design: a QR code is a convenience on top of the payment
     * instruction printed as text, never the only way to pay. Any failure
     * here (missing GD, a library error) must degrade to no QR, never fail
     * the whole PDF — a legal document must always be produced.
     */
    public static function dataUri(string $spayd): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        try {
            return (new PngWriter)->write(new QrCode($spayd))->getDataUri();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * SPAYD amounts are major units with a dot and up to two decimals; Money
     * stores minor units, so 45000 -> "450.00". Never a float in between.
     */
    private static function amount(Money $amount): string
    {
        $sign = $amount->amount < 0 ? '-' : '';
        $abs = abs($amount->amount);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The variable symbol is numeric, max 10 digits: strip anything else so a
     * non-numeric order-number scheme still yields a valid VS.
     */
    private static function variableSymbol(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return substr($digits, 0, 10);
    }

    /**
     * Keeps only the characters that can legitimately appear in an
     * account/IBAN, uppercased, with no spaces.
     */
    private static function sanitizeAccount(string $account): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9\/+-]/', '', $account));
    }
}
