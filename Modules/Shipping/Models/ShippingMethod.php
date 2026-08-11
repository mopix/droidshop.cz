<?php

namespace Modules\Shipping\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One way a shop delivers an order (spec §16.5).
 *
 * Personal pickup, a flat-rate carrier, or an API-backed carrier (Packeta,
 * wave 2.5); the enum provider column widens with a migration per new
 * carrier, it does not accept arbitrary keys.
 *
 * Implements the kernel's read-only ShippingOption shape directly, the way
 * Customer implements CustomerAccount, so checkout never touches this model.
 */
class ShippingMethod extends Model implements ShippingOption
{
    use BelongsToTenant;

    public const PROVIDER_PICKUP = 'pickup';

    public const PROVIDER_FLAT = 'flat';

    public const PROVIDER_PACKETA = 'packeta';

    /**
     * Zásilkovna delivering to the shopper's own address through a partner
     * carrier (PPL/DPD/GLS/Česká pošta) rather than to a branch — a separate
     * shipping_methods row from PROVIDER_PACKETA, with its own credentials
     * and price, exactly like PROVIDER_PICKUP and PROVIDER_FLAT are separate
     * rows rather than a flag on one.
     */
    public const PROVIDER_PACKETA_HD = 'packeta_hd';

    protected $guarded = [];

    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'free_from' => MoneyCast::class,
            // Holds a credential since wave 2.5 (Packeta apiPassword), so it is
            // encrypted at rest exactly like payment_methods.settings. The
            // pickup address inside it is not secret; the column is, because
            // one column cannot be half-encrypted.
            'settings' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function paymentMethods(): BelongsToMany
    {
        // Explicit table: our pivot is shipping_method_payment_method, not
        // Laravel's alphabetical default payment_method_shipping_method.
        return $this->belongsToMany(PaymentMethod::class, 'shipping_method_payment_method')->withTimestamps();
    }

    public function id(): int
    {
        return (int) $this->getKey();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function freeFrom(): ?Money
    {
        return $this->free_from;
    }

    public function taxRateId(): ?int
    {
        return $this->tax_rate_id;
    }

    public function provider(): string
    {
        // Reads the raw attribute, not $this->provider: a method named the
        // same as the column would otherwise shadow Eloquent's __get and
        // return itself instead of the stored value.
        return $this->attributes['provider'];
    }

    public function defaultWeightGrams(): ?int
    {
        return $this->packetaDefaultWeightG();
    }

    /**
     * Whether this row's provider is one of the Packeta family — branch
     * pickup (PROVIDER_PACKETA) or address delivery through a partner
     * carrier (PROVIDER_PACKETA_HD). Both keep their credentials in the same
     * settings shape (api_key/eshop/api_password/default_weight_g), each on
     * its own row (2026-08-11 decision: not shared, the tenant enters them
     * twice) — so one guard serves both rather than duplicating every
     * accessor below (task 5, home-delivery wave: these were hard-scoped to
     * PROVIDER_PACKETA alone until this widening).
     */
    private function isPacketaFamily(): bool
    {
        return in_array($this->provider(), [self::PROVIDER_PACKETA, self::PROVIDER_PACKETA_HD], true);
    }

    /**
     * The Packeta API key — not a secret, shown in the admin so the shop can
     * see which account is wired. Null for any provider outside the Packeta
     * family.
     */
    public function packetaApiKey(): ?string
    {
        if (! $this->isPacketaFamily()) {
            return null;
        }

        $key = $this->settings['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The Packeta eshop identifier — not a secret. Null for any provider
     * outside the Packeta family.
     */
    public function packetaEshop(): ?string
    {
        if (! $this->isPacketaFamily()) {
            return null;
        }

        $eshop = $this->settings['eshop'] ?? null;

        return is_string($eshop) && $eshop !== '' ? $eshop : null;
    }

    /**
     * The default parcel weight (grams) used when a product carries none.
     */
    public function packetaDefaultWeightG(): ?int
    {
        if (! $this->isPacketaFamily()) {
            return null;
        }

        $weight = $this->settings['default_weight_g'] ?? null;

        return is_numeric($weight) ? (int) $weight : null;
    }

    /**
     * Whether a Packeta API password is stored — so the admin can show
     * "heslo nastaveno" and a "změnit" affordance without ever receiving the
     * password itself.
     */
    public function apiPasswordSet(): bool
    {
        return $this->isPacketaFamily() && filled($this->settings['api_password'] ?? null);
    }

    /**
     * The partner carrier's own catalog id (PPL/DPD/GLS/Česká pošta) that
     * PROVIDER_PACKETA_HD delivers through — never meaningful for branch
     * pickup, which has no partner carrier to name.
     */
    public function packetaCarrierId(): ?string
    {
        if ($this->provider() !== self::PROVIDER_PACKETA_HD) {
            return null;
        }

        $carrierId = $this->settings['carrier_id'] ?? null;

        return is_string($carrierId) && $carrierId !== '' ? $carrierId : null;
    }
}
