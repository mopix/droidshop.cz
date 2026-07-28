<?php

namespace Modules\Feeds\Http\Controllers;

use App\Core\Shipping\Contracts\ShippingOptions;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Modules\Feeds\Models\ProductFeed;
use Modules\Feeds\Support\FeedItemBuilder;

/**
 * The public XML a comparison shopper downloads.
 *
 * Built on request and cached, not written to disk — the same trade the
 * sitemap makes: a stale file is worse than a slightly expensive first hit.
 *
 * A feed that is off returns 404 rather than an empty document, because an
 * empty feed reads to the comparison shopper as "the shop has nothing" and
 * gets the whole catalogue delisted.
 */
class FeedController
{
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly TenantContext $context,
        private readonly FeedItemBuilder $builder,
        private readonly ShippingOptions $shipping,
    ) {}

    public function __invoke(string $type): Response
    {
        abort_unless(in_array($type, ProductFeed::TYPES, true), 404);

        $tenant = $this->context->current();

        abort_if($tenant === null, 404);

        $feed = ProductFeed::query()->where('type', $type)->first();

        abort_if($feed === null || ! $feed->enabled, 404);

        $xml = Cache::remember(
            'feed:'.$tenant->id.':'.$type,
            self::CACHE_TTL,
            fn () => $this->render($feed),
        );

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function render(ProductFeed $feed): string
    {
        return view('feeds::'.$feed->type, [
            'items' => $this->builder->items($feed->type, $feed->deliveryDays()),
            // Without the shipping module this is empty and the DELIVERY block
            // is skipped entirely — a missing block is honest, a zero price
            // would promise free delivery the shop never offered.
            'shipping' => $this->shipping->available(0),
        ])->render();
    }
}
