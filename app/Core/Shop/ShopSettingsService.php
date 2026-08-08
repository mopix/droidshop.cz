<?php

namespace App\Core\Shop;

use App\Core\PageCache\Generations;
use App\Core\Tenancy\TenantContext;
use App\Models\ShopSettings;
use App\Models\Tenant;

/**
 * The single read and write path for a shop's own settings (wave 3.6).
 *
 * Reads never return null. A tenant who has not opened the screens yet gets an
 * unsaved instance carrying the defaults, so the storefront and the admin form
 * both render before anybody saves anything — the same reasoning as
 * SettingsService::all() merging a module's schema defaults: the defaults are
 * the single truth about what an untouched shop runs on, not a `?? '…'`
 * scattered across every caller.
 *
 * Writes bump every page-cache generation. That is deliberately blunter than
 * the model observer, which maps one model to one dimension: a tagline is
 * theme-ish, the footer contact is content, hiding empty categories is
 * catalogue, and splitting the write by field would mean the next field added
 * is the one that gets the wrong dimension. Settings change a handful of times
 * in a shop's life, so dropping the whole cache costs nothing measurable.
 */
class ShopSettingsService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Generations $generations,
    ) {}

    public function forCurrentTenant(): ShopSettings
    {
        $tenant = $this->context->current();

        return $tenant === null ? new ShopSettings : $this->forTenant($tenant);
    }

    public function forTenant(Tenant $tenant): ShopSettings
    {
        return ShopSettings::query()->firstWhere('tenant_id', $tenant->id)
            ?? new ShopSettings(['tenant_id' => $tenant->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): ShopSettings
    {
        $tenant = $this->context->current();

        $settings = ShopSettings::updateOrCreate(['tenant_id' => $tenant->id], $data);

        $this->generations->bumpAll($tenant);

        return $settings;
    }
}
