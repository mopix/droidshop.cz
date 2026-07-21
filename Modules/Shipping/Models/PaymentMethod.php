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

    // Online gateway (module payments, wave 1.4). settings holds the tenant's
    // Comgate credentials (merchant, secret, test), encrypted like the QR
    // account above.
    public const PROVIDER_COMGATE = 'comgate';

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

    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * The account the customer pays a bank transfer to — the destination
     * printed on the payment instruction (the QR), never withheld. Only ever
     * set for bank-transfer methods; every other provider has none.
     */
    public function spaydAccount(): ?string
    {
        if ($this->provider !== self::PROVIDER_BANK_TRANSFER) {
            return null;
        }

        return $this->accountValue();
    }

    /**
     * Whether an account for the QR payment is stored at all.
     *
     * The admin needs to know the account is set without ever seeing it, so the
     * screen can show "účet nastaven" and a "změnit" affordance.
     */
    public function accountSet(): bool
    {
        return $this->accountValue() !== null;
    }

    /**
     * The last four characters of the stored account, for display — never the
     * whole thing. The full account leaves the server for no request.
     */
    public function maskedAccount(): ?string
    {
        $account = $this->accountValue();

        return $account === null ? null : '…'.substr($account, -4);
    }

    /**
     * The Comgate merchant id — not a secret, shown in the admin so the shop
     * can see which account is wired. Null for any other provider.
     */
    public function comgateMerchant(): ?string
    {
        if ($this->provider !== self::PROVIDER_COMGATE) {
            return null;
        }

        $merchant = $this->settings['merchant'] ?? null;

        return is_string($merchant) && $merchant !== '' ? $merchant : null;
    }

    /**
     * Whether the gateway runs in Comgate's test mode.
     */
    public function comgateTest(): bool
    {
        return $this->provider === self::PROVIDER_COMGATE && (bool) ($this->settings['test'] ?? false);
    }

    /**
     * Whether a Comgate secret is stored — so the admin can show "secret set"
     * and a "change" affordance without ever receiving the secret itself.
     */
    public function secretSet(): bool
    {
        return $this->provider === self::PROVIDER_COMGATE && ! empty($this->settings['secret']);
    }

    private function accountValue(): ?string
    {
        $settings = $this->settings ?? [];
        // `account` is written by the admin; `iban` covers rows seeded directly.
        $account = $settings['account'] ?? $settings['iban'] ?? null;

        return ($account === null || $account === '') ? null : (string) $account;
    }
}
