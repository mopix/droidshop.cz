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
 * Personal pickup or a flat-rate carrier in this wave; the provider column
 * leaves room for API-backed carriers later without a schema change.
 *
 * Implements the kernel's read-only ShippingOption shape directly, the way
 * Customer implements CustomerAccount, so checkout never touches this model.
 */
class ShippingMethod extends Model implements ShippingOption
{
    use BelongsToTenant;

    public const PROVIDER_PICKUP = 'pickup';

    public const PROVIDER_FLAT = 'flat';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'free_from' => MoneyCast::class,
            'settings' => 'array',
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
}
