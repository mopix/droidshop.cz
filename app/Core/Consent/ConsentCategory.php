<?php

namespace App\Core\Consent;

/**
 * The three groups a visitor decides about (spec §15.6 sousedství, vlna 3.3).
 *
 * Necessary is in the enum even though it can never be refused: the banner
 * has to list it, the settings screen has to show it as permanently on, and
 * code that asks "may I do X" should be able to ask the same question about
 * every category rather than special-casing one.
 */
enum ConsentCategory: string
{
    /** Session, XSRF, the cart, and the consent record itself. */
    case Necessary = 'necessary';

    /** GA4. */
    case Analytics = 'analytics';

    /** Sklik retargeting, Meta Pixel. */
    case Marketing = 'marketing';

    /**
     * Whether refusing is even an option.
     *
     * § 89 odst. 3 zákona č. 127/2005 Sb.: no consent is required for cookies
     * strictly necessary to deliver the service the visitor asked for. Without
     * them the shop cannot log anyone in or finish an order.
     */
    public function isRefusable(): bool
    {
        return $this !== self::Necessary;
    }

    public function label(): string
    {
        return match ($this) {
            self::Necessary => 'Nezbytné',
            self::Analytics => 'Analytické',
            self::Marketing => 'Marketingové',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Necessary => 'Bez nich e-shop nefunguje — přihlášení, košík a zabezpečení formulářů. Nelze je vypnout.',
            self::Analytics => 'Měří návštěvnost, aby provozovatel věděl, které stránky lidi zajímají.',
            self::Marketing => 'Umožňují měřit účinnost reklamy a zobrazovat ji cíleně.',
        };
    }

    /**
     * @return list<self>
     */
    public static function refusable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case->isRefusable()));
    }
}
