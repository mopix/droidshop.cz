<?php

namespace Modules\Docs\Services;

use App\Core\Documents\Contracts\DocumentBook;
use App\Core\Documents\Contracts\DocumentView;
use App\Core\Orders\Contracts\OrderBook;
use Illuminate\Support\Collection;
use Modules\Docs\Models\Document;
use Modules\Storefront\Support\ShopModules;

/**
 * The docs module's answer to the kernel's read contract.
 *
 * Gates on the module being active for the current tenant first, the same
 * way EloquentOrderBook does: a deactivated module's rows must never leak
 * into an order detail page just because the table still has them. Separate
 * from InvoiceIssuer on purpose — see DocumentBook's own docblock for why
 * reading and issuing are two different contracts here.
 */
class EloquentDocumentBook implements DocumentBook
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly OrderBook $orders,
    ) {}

    /**
     * @return Collection<int, DocumentView>
     */
    public function forOrder(string $orderUuid): Collection
    {
        if (! $this->modules->has('docs')) {
            return new Collection;
        }

        $order = $this->orders->findForAdmin($orderUuid);

        if ($order === null) {
            return new Collection;
        }

        return Document::query()
            ->where('order_id', $order->orderInternalId())
            ->orderByDesc('issued_at')
            ->get();
    }
}
