<?php

namespace Modules\Checkout\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Modules\Checkout\Services\CartMerger;

/**
 * Merges a shopper's anonymous cart into their account the moment they sign
 * in (spec, rozhodnutí 7). Wired in Modules\Checkout\Providers\ModuleProvider,
 * which is what keeps the customers module unaware that carts exist at all.
 */
class MergeCartOnCustomerLogin
{
    public function __construct(
        private readonly CartMerger $merger,
        private readonly Request $request,
    ) {}

    public function handle(Login $event): void
    {
        // Illuminate\Auth\Events\Login fires for every guard — a tenant
        // staff login (guard 'web') or a superadmin login (guard 'platform')
        // has no shopper cart to merge, and must never be mistaken for one.
        if ($event->guard !== 'customer') {
            return;
        }

        $this->merger->mergeOnLogin($this->request, (int) $event->user->getAuthIdentifier());
    }
}
