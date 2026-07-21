<?php

namespace Modules\Orders\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Order extends Model implements OrderView
{
    use BelongsToTenant;

    public const FULFILLMENT_NEW = 'new';

    public const FULFILLMENT_PROCESSING = 'processing';

    public const FULFILLMENT_SHIPPED = 'shipped';

    public const FULFILLMENT_DELIVERED = 'delivered';

    public const FULFILLMENT_CANCELLED = 'cancelled';

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_FAILED = 'failed';

    public const SOURCE_STOREFRONT = 'storefront';

    public const SOURCE_MANUAL = 'manual';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'billing' => 'array',
            'shipping' => 'array',
            'shipping_snapshot' => 'array',
            'payment_snapshot' => 'array',
            'vat_summary' => 'array',
            'items_total' => MoneyCast::class,
            'shipping_total' => MoneyCast::class,
            'payment_fee' => MoneyCast::class,
            'total' => MoneyCast::class,
            'placed_at' => 'datetime',
        ];
    }

    /**
     * Every order gets a uuid at creation, never left to a caller to
     * remember — it is the public identifier used in URLs and the
     * order_idem_unique lookup, and must exist from the first insert.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->uuid ??= (string) Str::uuid();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    // --- OrderView ----------------------------------------------------
    //
    // Named `order*` rather than after the attribute so `orderItems()`
    // cannot collide with items(), the Eloquent relation above that the
    // module itself uses to write line items (see
    // App\Core\Orders\Contracts\OrderView).

    public function orderUuid(): string
    {
        return $this->uuid;
    }

    public function orderNumber(): string
    {
        return $this->number;
    }

    public function orderCustomerId(): ?int
    {
        return $this->customer_id;
    }

    public function orderEmail(): string
    {
        return $this->email;
    }

    public function orderPhone(): ?string
    {
        return $this->phone;
    }

    public function orderFulfillmentStatus(): string
    {
        return $this->fulfillment_status;
    }

    public function orderPaymentStatus(): string
    {
        return $this->payment_status;
    }

    public function orderItemsTotal(): Money
    {
        return $this->items_total;
    }

    public function orderShippingTotal(): Money
    {
        return $this->shipping_total;
    }

    public function orderTotal(): Money
    {
        return $this->total;
    }

    public function orderCurrency(): string
    {
        return $this->currency;
    }

    public function orderPlacedAt(): ?Carbon
    {
        return $this->placed_at;
    }

    public function orderItems(): Collection
    {
        // A fresh query, not the cached relation: a caller must never have
        // to remember to refresh() to see a line item written after this
        // Order instance was loaded (see CartShape's own docblock for the
        // same rule).
        return $this->items()->get();
    }

    public function orderPaymentSnapshot(): ?array
    {
        return $this->payment_snapshot;
    }
}
