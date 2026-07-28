<?php

namespace Modules\Products\Support;

use Modules\Products\Models\Product;

/**
 * The single source of truth about the CSV format.
 *
 * Import and export both read it, which is what keeps the round trip honest:
 * a merchant exports the catalogue, edits it and uploads it back, and the two
 * sides cannot drift apart because there is only one list of columns.
 */
class ProductCsvSchema
{
    public const TYPE_PRODUCT = 'produkt';

    public const TYPE_VARIANT = 'varianta';

    /** @var list<string> */
    public const COLUMNS = [
        'typ', 'sku', 'varianta_rodic_sku', 'varianta_hodnoty',
        'nazev', 'slug', 'stav',
        'cena', 'akcni_cena', 'akce_od', 'akce_do', 'dph',
        'ean', 'hmotnost_g',
        'sklad_sleduje', 'sklad_ks', 'sklad_politika',
        'kategorie', 'vyrobce',
        'kratky_popis', 'popis', 'seo_titulek', 'seo_popis',
    ];

    /** Only ever exported, and only to someone with products.costs. */
    public const COLUMN_PURCHASE_PRICE = 'nakupni_cena';

    /** @var array<string, string> */
    public const STATUSES = [
        'koncept' => Product::STATUS_DRAFT,
        'aktivni' => Product::STATUS_ACTIVE,
        'skryty' => Product::STATUS_HIDDEN,
    ];

    /** @var array<string, string> */
    public const STOCK_POLICIES = [
        'skryt' => Product::STOCK_POLICY_HIDE,
        'vyprodano' => Product::STOCK_POLICY_SOLD_OUT,
        'na_objednavku' => Product::STOCK_POLICY_BACKORDER,
    ];

    /**
     * "1 290,00" → 129000. Haléře, never a float on the way to the database.
     *
     * Strips ordinary and non-breaking spaces, because Excel writes the
     * thousands separator as U+00A0 and a merchant will never see it.
     */
    public static function money(?string $raw): ?int
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $normalised = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $raw);
        $normalised = str_replace(',', '.', $normalised);

        if (! is_numeric($normalised)) {
            return null;
        }

        return (int) round(((float) $normalised) * 100);
    }

    public static function formatMoney(int $amount): string
    {
        return number_format($amount / 100, 2, ',', '');
    }

    public static function bool(?string $raw): ?bool
    {
        $raw = mb_strtolower(trim((string) $raw));

        return match ($raw) {
            'ano', '1', 'true' => true,
            'ne', '0', 'false' => false,
            default => null,
        };
    }
}
