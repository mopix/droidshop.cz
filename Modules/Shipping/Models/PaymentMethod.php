<?php

namespace Modules\Shipping\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One way a shop takes payment (spec §16.5).
 *
 * Offline only in this wave — cash on delivery and bank transfer with a QR
 * code. An online gateway is its own module (wave 1.4), which is why the
 * settings that can hold a credential are encrypted here already.
 *
 * Implements the kernel's read-only PaymentOption shape directly so checkout
 * never touches this model.
 */
class PaymentMethod extends Model implements PaymentOption
{
    use BelongsToTenant;

    public const PROVIDER_COD = 'cod';

    public const PROVIDER_BANK_TRANSFER = 'bank_transfer';

    protected $guarded = [];

    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return [
            'fee' => MoneyCast::class,
            // Encrypted at rest: a bank account for QR is a credential in the
            // §16.5 sense. The admin never receives it back in the clear.
            'settings' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function shippingMethods(): BelongsToMany
    {
        // Explicit table: our pivot is shipping_method_payment_method, not
        // Laravel's alphabetical default payment_method_shipping_method.
        return $this->belongsToMany(ShippingMethod::class, 'shipping_method_payment_method')->withTimestamps();
    }

    public function id(): int
    {
        return (int) $this->getKey();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function fee(): Money
    {
        return $this->fee;
    }

    public function taxRateId(): ?int
    {
        return $this->tax_rate_id;
    }
}
