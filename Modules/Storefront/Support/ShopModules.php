<?php

namespace Modules\Storefront\Support;

use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;

/**
 * What the current shop actually runs.
 *
 * The theme deliberately declares no dependencies: a manifest `requires` would
 * make the catalogue undeactivatable, because the theme is a core module and
 * nothing may be switched off underneath one. So the theme asks at request
 * time instead and renders what is there — a shop with the catalogue switched
 * off gets a homepage without products, not a broken page.
 */
class ShopModules
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantContext $context,
    ) {}

    public function has(string $key): bool
    {
        $tenant = $this->context->current();

        return $tenant !== null && $this->registry->isEnabled($tenant, $key);
    }
}
