<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one shop presents and behaves: name and tagline, the contact a customer
 * writes to, what a search engine is told, and whether the shop is open at all
 * (wave 3.6).
 *
 * Deliberately without a BelongsToTenant scope, for the same reason as
 * TenantTheme: this is read from the tenant resolved for the request inside a
 * view composer, not through a request-scoped model query, so the scope would
 * add nothing but a trap for the one place (superadmin) that may legitimately
 * read another shop's row.
 *
 * A tenant that never opened the screens has no row. Every reader therefore
 * goes through ShopSettingsService, which hands back an unsaved instance
 * carrying the defaults below — the storefront must render before anyone
 * saves anything.
 */
class ShopSettings extends Model
{
    protected $table = 'shop_settings';

    protected $guarded = [];

    /** Formats offered on the screen; anything else is refused on write. */
    public const DATE_FORMATS = ['j. n. Y', 'd.m.Y', 'j. F Y', 'Y-m-d'];

    public const TIME_FORMATS = ['H:i', 'H:i:s', 'G:i'];

    public const DEFAULT_TIMEZONE = 'Europe/Prague';

    public const DEFAULT_EMPTY_SEARCH_TEXT = 'Nic jsme nenašli. Zkuste jiné slovo nebo se podívejte do kategorií.';

    /**
     * Defaults for a tenant with no row yet. They repeat the migration's
     * column defaults on purpose: the unsaved instance never touches the
     * database, so the database's defaults would never apply to it.
     */
    protected $attributes = [
        'timezone' => self::DEFAULT_TIMEZONE,
        'date_format' => 'j. n. Y',
        'time_format' => 'H:i',
        'noindex' => false,
        'hide_empty_categories' => false,
        'show_footer_contact' => true,
        'locked' => false,
    ];

    protected function casts(): array
    {
        return [
            'noindex' => 'boolean',
            'hide_empty_categories' => 'boolean',
            'show_footer_contact' => 'boolean',
            'locked' => 'boolean',
            // Hashing on the cast, not at the call site: the lock password is
            // a credential, and a plaintext column would be one forgotten
            // Hash::make() away.
            'lock_password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The homepage title. Empty degrades to the shop's name rather than to an
     * empty <title>, which is worse than the automatic one it replaced.
     */
    public function seoTitleOr(string $shopName): string
    {
        return $this->filled('seo_title') ? (string) $this->seo_title : $shopName;
    }

    public function emptySearchText(): string
    {
        return $this->filled('empty_search_text')
            ? (string) $this->empty_search_text
            : self::DEFAULT_EMPTY_SEARCH_TEXT;
    }

    /**
     * @return list<array{label: string, value: string, href: ?string}>
     */
    public function contactLines(): array
    {
        $lines = [];

        if ($this->filled('contact_email')) {
            $lines[] = ['label' => 'E-mail', 'value' => (string) $this->contact_email, 'href' => 'mailto:'.$this->contact_email];
        }

        if ($this->filled('contact_phone')) {
            $lines[] = ['label' => 'Telefon', 'value' => (string) $this->contact_phone, 'href' => 'tel:'.preg_replace('/\s+/', '', (string) $this->contact_phone)];
        }

        $address = $this->address();

        if ($address !== null) {
            $lines[] = ['label' => 'Adresa', 'value' => $address, 'href' => null];
        }

        if ($this->filled('opening_hours')) {
            $lines[] = ['label' => 'Otevírací doba', 'value' => (string) $this->opening_hours, 'href' => null];
        }

        return $lines;
    }

    public function address(): ?string
    {
        $parts = array_filter([
            $this->contact_street,
            trim(($this->contact_zip ?? '').' '.($this->contact_city ?? '')),
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @return list<array{network: string, url: string}>
     */
    public function socialLinks(): array
    {
        $networks = [
            'facebook_url' => 'Facebook',
            'instagram_url' => 'Instagram',
            'x_url' => 'X',
            'youtube_url' => 'YouTube',
            'tiktok_url' => 'TikTok',
        ];

        $links = [];

        foreach ($networks as $column => $network) {
            if ($this->filled($column)) {
                $links[] = ['network' => $network, 'url' => (string) $this->{$column}];
            }
        }

        return $links;
    }

    /**
     * Anything at all to put in the footer box. Without this the box would
     * render as an empty heading for every shop that filled nothing in.
     */
    public function hasContactDetails(): bool
    {
        return $this->contactLines() !== [] || $this->socialLinks() !== [];
    }

    private function filled(string $attribute): bool
    {
        return trim((string) ($this->{$attribute} ?? '')) !== '';
    }
}
