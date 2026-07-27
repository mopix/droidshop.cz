<?php

namespace Modules\Packeta\Models;

use App\Core\Shipping\Contracts\PickupPoint as PickupPointContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One carrier pickup point (wave 2.5).
 *
 * No BelongsToTenant on purpose — the catalogue is platform-wide (see the
 * migration). Implements the kernel's read-only shape directly, the way
 * ShippingMethod implements ShippingOption, so checkout never touches it.
 */
class PickupPoint extends Model implements PickupPointContract
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Lowercase, diacritics stripped — the form both search_text and every
     * query term are put in, so the two can be compared at all.
     *
     * Mirrors Modules\Products\Support\SearchText::normalise (rozhodnutí
     * 2026-07-20) rather than ext-intl's transliterator: the project already
     * has one diacritics-folding convention, and a second one would fold the
     * same text differently depending on who wrote it.
     */
    public static function normalise(string $value): string
    {
        $text = Str::lower(Str::ascii($value));

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public function pointCarrier(): string
    {
        return $this->carrier;
    }

    public function pointCode(): string
    {
        return $this->code;
    }

    public function pointName(): string
    {
        return $this->name;
    }

    public function pointStreet(): string
    {
        return $this->street;
    }

    public function pointCity(): string
    {
        return $this->city;
    }

    public function pointZip(): string
    {
        return $this->zip;
    }

    public function pointOpeningHours(): ?array
    {
        return $this->opening_hours;
    }
}
