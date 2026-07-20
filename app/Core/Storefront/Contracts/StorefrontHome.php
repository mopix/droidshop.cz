<?php

namespace App\Core\Storefront\Contracts;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Whatever answers `/` on a shop's own domain.
 *
 * The root path is the one URL the kernel cannot hand to a module the usual
 * way: the core web routes are registered before module routes, so a module
 * route for `/` would never be reached. Core therefore keeps the route and
 * asks for this binding instead. The theme module binds it; the kernel never
 * learns which module that is, it only asks the implementation for its key so
 * the kill switch and per-tenant activation still apply.
 */
interface StorefrontHome
{
    /**
     * The module key this implementation belongs to, so the kernel can check
     * the shop actually runs it.
     */
    public function moduleKey(): string;

    public function render(Request $request): View;
}
