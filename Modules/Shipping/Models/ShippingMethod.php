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

    /**
     * The Packeta API key — not a secret, shown in the admin so the shop can
     * see which account is wired. Null for any other provider.
     */
    public function packetaApiKey(): ?string
    {
        if ($this->provider() !== self::PROVIDER_PACKETA) {
            return null;
        }

        $key = $this->settings['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The Packeta eshop identifier — not a secret. Null for any other provider.
     */
    public function packetaEshop(): ?string
    {
        if ($this->provider() !== self::PROVIDER_PACKETA) {
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
        if ($this->provider() !== self::PROVIDER_PACKETA) {
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
        return $this->provider() === self::PROVIDER_PACKETA && filled($this->settings['api_password'] ?? null);
    }
}
