<?php

namespace Modules\Payments\Jobs;

use App\Core\Orders\Contracts\OrderSettlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fails an online-payment order that was never paid, and returns its stock
 * (plan decision 5, wave 1.4).
 *
 * Dispatched with a delay when a gateway payment is started, so an abandoned
 * or timed-out card payment does not hold the stock the placement took forever.
 * The whole decision is deferred to OrderSettlement::settleFailed, which only
 * acts on an order still unpaid: an order paid in the meantime — the normal
 * case — makes this a silent no-op, and a second run cannot return stock twice.
 *
 * Tenant-aware by default (config/multitenancy.php): dispatched inside a
 * tenant's request, it runs against that tenant when the worker picks it up.
 */
class ExpireUnpaidOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $orderUuid) {}

    public function handle(OrderSettlement $settlement): void
    {
        $settlement->settleFailed(
            $this->orderUuid,
            returnStock: true,
            note: 'Platba nebyla dokončena v časovém limitu, objednávka byla uvolněna.',
        );
    }
}
