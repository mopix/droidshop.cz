<?php

namespace Modules\Shipping\Models;

use App\Core\Money\MoneyCast;
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
 */
class ShippingMethod extends Model
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
        return $this->belongsToMany(PaymentMethod::class)->withTimestamps();
    }
}
