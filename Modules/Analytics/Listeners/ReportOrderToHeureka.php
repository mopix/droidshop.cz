<?php

namespace Modules\Analytics\Listeners;

use App\Core\Orders\Contracts\OrderView;
use Modules\Analytics\Services\HeurekaVerified;

/**
 * Hands a completed order to Heureka's questionnaire service.
 *
 * Hangs off the orders module's own domain event rather than the checkout
 * controller: an order can also be created by hand in the admin, and a
 * questionnaire should follow that one too.
 */
class ReportOrderToHeureka
{
    public function __construct(private readonly HeurekaVerified $heureka) {}

    public function handle(object $event): void
    {
        $order = $event->order ?? null;

        if ($order instanceof OrderView) {
            $this->heureka->report($order);
        }
    }
}
