<?php

namespace Modules\Packeta\Models;

use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Shipping\Contracts\ShipmentView;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One parcel handed to a carrier (wave 2.5).
 *
 * Implements the kernel's read-only ShipmentView directly, the way
 * ShippingMethod implements ShippingOption, so the orders module renders a
 * shipment block without ever loading this class.
 */
class Shipment extends Model implements ShipmentView
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    /**
     * Transient claim state (fix round 1/5): exactly one live request holds a
     * row here, between the atomic claim and the carrier's answer — see
     * Modules\Packeta\Services\ShipmentSubmitter::claimForSubmission(). Never
     * set directly outside that atomic UPDATE. A row a process crashed on
     * while in this state is reclaimable again once `claimed_at` is older
     * than config('packeta.submit_stale_after_minutes') (fix round 2/5).
     */
    public const STATUS_SUBMITTING = 'submitting';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cod_amount' => MoneyCast::class,
            'claimed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'label_printed_at' => 'datetime',
        ];
    }

    public function shipmentId(): int
    {
        return (int) $this->getKey();
    }

    public function shipmentCarrier(): string
    {
        return $this->carrier;
    }

    public function shipmentStatus(): string
    {
        return $this->attributes['status'];
    }

    public function shipmentPacketId(): ?string
    {
        return $this->packet_id;
    }

    public function shipmentBarcode(): ?string
    {
        return $this->barcode;
    }

    public function shipmentCodAmount(): Money
    {
        return $this->cod_amount;
    }

    public function shipmentError(): ?string
    {
        return $this->error;
    }

    public function shipmentSubmittedAt(): ?Carbon
    {
        return $this->submitted_at;
    }
}
