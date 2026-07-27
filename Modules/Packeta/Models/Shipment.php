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

    /**
     * ShipmentView's read-only mirror of isResubmittable() below — added in
     * wave 2.5 task 15 so the order detail's "Podat do Zásilkovny" button
     * (reached only through the kernel contract, never this class) can ask
     * the same question the dispatch queue already asks directly.
     */
    public function shipmentIsResubmittable(): bool
    {
        return $this->isResubmittable();
    }

    /**
     * The instant before which a `submitting` row counts as abandoned rather
     * than genuinely in flight — the single source of truth for
     * config('packeta.submit_stale_after_minutes'), so that config read never
     * happens in two places with any chance of drifting apart.
     *
     * Modules\Packeta\Services\ShipmentSubmitter::claimForSubmission() uses
     * this as the cutoff in its atomic compare-and-swap UPDATE (the actual
     * authority on which request may call the carrier); isResubmittable()
     * below uses the exact same cutoff to decide whether an already-loaded
     * row LOOKS reclaimable, for a read-only listing that never claims
     * anything itself (wave 2.5, task 14, fix round 1/5).
     */
    public static function staleBefore(): Carbon
    {
        return now()->subMinutes((int) config('packeta.submit_stale_after_minutes'));
    }

    /**
     * True when Modules\Packeta\Services\ShipmentSubmitter::submit() would
     * actually accept another attempt at this row: `pending`/`failed`/
     * `cancelled` unconditionally, or a `submitting` row whose claim is older
     * than staleBefore() — mirroring claimForSubmission()'s own WHERE clause
     * so the two can never quietly disagree about which shipments are still
     * retryable.
     *
     * `cancelled` counts on purpose (final review, wave 2.5): before this, a
     * shipment cancelled through ShipmentAdminController::cancel() — a
     * misclick, or a genuine change of mind that the order should ship after
     * all — could never be handed to the carrier again. The dispatch queue
     * excludes anything not resubmittable, and the unique (tenant_id,
     * order_id) index means there is no second row to fall back to, so a
     * cancelled shipment was an order silently stuck outside every admin
     * action forever.
     *
     * Deliberately NOT itself a claim: this is a plain read over an
     * already-loaded model, used by the dispatch queue listing to decide
     * what to show, not to decide who may call the carrier. A row can look
     * resubmittable here and still lose the actual claim a moment later to a
     * concurrent request — claimForSubmission()'s atomic UPDATE remains the
     * only real authority on that.
     */
    public function isResubmittable(): bool
    {
        if (in_array($this->shipmentStatus(), [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return true;
        }

        if ($this->shipmentStatus() !== self::STATUS_SUBMITTING || $this->claimed_at === null) {
            return false;
        }

        return $this->claimed_at->lt(self::staleBefore());
    }
}
