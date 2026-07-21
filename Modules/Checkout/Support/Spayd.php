<?php

namespace Modules\Checkout\Support;

use App\Core\Money\Money;
use Illuminate\Support\Str;

/**
 * Builds a SPAYD string (Short Payment Descriptor, the Czech QR-payment
 * standard) for a bank-transfer order, e.g.:
 *
 *   SPD*1.0*ACC:CZ6508000000192000145399*AM:450.00*CC:CZK*X-VS:2026001*MSG:Objednavka 2026001
 *
 * Everything on the string is a payment instruction the customer is meant to
 * act on: the destination account, the amount to pay, the variable symbol
 * that lets the shop match the incoming transfer to the order. Nothing secret
 * belongs here — the account is the pay-to destination, not a credential to
 * withhold (spec §16.5).
 *
 * The amount and variable symbol are always the server's own figures (the
 * order total and number), never anything a client posted (AK 5).
 */
final class Spayd
{
    /**
     * @param  string  $account  The pay-to account/IBAN from the payment method's settings
     * @param  Money  $amount  The order total, in minor units
     * @param  string  $variableSymbol  The order number (digits only)
     * @param  string|null  $message  A short human note; defaults to "Objednavka {vs}"
     */
    public static function forBankTransfer(string $account, Money $amount, string $variableSymbol, ?string $message = null): string
    {
        $vs = self::variableSymbol($variableSymbol);

        $fields = [
            'ACC:'.self::sanitizeAccount($account),
            'AM:'.self::amount($amount),
            'CC:'.self::sanitizeValue($amount->currency),
            'X-VS:'.$vs,
            'MSG:'.self::sanitizeValue($message ?? ('Objednavka '.$vs)),
        ];

        return 'SPD*1.0*'.implode('*', $fields);
    }

    /**
     * SPAYD amounts are major units with a dot and up to two decimals; the
     * order stores minor units, so 45000 → "450.00". Never a float in between.
     */
    private static function amount(Money $amount): string
    {
        $sign = $amount->amount < 0 ? '-' : '';
        $abs = abs($amount->amount);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The variable symbol is numeric, max 10 digits: strip anything else and
     * clamp, so a non-numeric order-number scheme still yields a valid VS.
     */
    private static function variableSymbol(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return substr($digits, 0, 10);
    }

    /**
     * The account is a bank account/IBAN: keep only the characters that can
     * legitimately appear in one, uppercased, with no spaces.
     */
    private static function sanitizeAccount(string $account): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9\/+-]/', '', $account));
    }

    /**
     * `*` is the SPAYD field delimiter and diacritics are not allowed in the
     * ASCII descriptor, so a free-text value (message, currency) is reduced to
     * a delimiter-free, diacritics-free string before it goes in.
     */
    private static function sanitizeValue(string $value): string
    {
        $ascii = Str::ascii($value);
        $ascii = str_replace(['*', '%'], ' ', $ascii);
        $ascii = (string) preg_replace('/\s+/', ' ', $ascii);

        return trim(substr($ascii, 0, 60));
    }
}
