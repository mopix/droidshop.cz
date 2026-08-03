<?php

namespace App\Core\PageCache;

use App\Core\Tenancy\TenantContext;
use App\Models\TenantTheme;
use Illuminate\Database\Eloquent\Model;

/**
 * One observer for every model whose contents show up on a cached page.
 *
 * Instrumenting the writers instead was rejected: ProductWriter and
 * VariantWriter alone have more than fifteen writing methods and keep
 * growing, so the sixteenth would be the one nobody remembers. An observer
 * also covers write paths that do not exist yet — the CSV importer already
 * goes through the writers, and the next importer might not.
 *
 * The stock write-off is the deliberate exception: it updates through the
 * query builder (EloquentProductCatalog::decrementStock) and fires no
 * Eloquent event, so it bumps for itself. See wave 3.0 spec, decision 8.
 *
 * The model → dimension map is keyed by class name string, not by
 * `instanceof` against a fully-qualified module class name: app/Core/ never
 * imports a module class at compile time (same convention as ModuleRegistry
 * and TenantProvisioner's string-resolved DefaultHomepage), because a
 * `use Modules\...` statement here would tie the core to a module that must
 * stay optional. TenantTheme is core, so it is the one entry imported
 * normally.
 */
class PageCacheObserver
{
    /**
     * @var array<class-string, Dimension>
     */
    public const DIMENSION_BY_MODEL = [
        TenantTheme::class => Dimension::Theme,
        'Modules\\Storefront\\Models\\HomepageBlock' => Dimension::Content,
        'Modules\\Pages\\Models\\Page' => Dimension::Content,
        'Modules\\Products\\Models\\Product' => Dimension::Catalog,
        'Modules\\Products\\Models\\ProductVariant' => Dimension::Catalog,
        'Modules\\Categories\\Models\\Category' => Dimension::Catalog,
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Generations $generations,
    ) {}

    public function saved(Model $model): void
    {
        $this->bumpFor($model);
    }

    public function deleted(Model $model): void
    {
        $this->bumpFor($model);
    }

    private function bumpFor(Model $model): void
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return;
        }

        $this->generations->bump($tenant, $this->dimensionFor($model));
    }

    private function dimensionFor(Model $model): Dimension
    {
        return self::DIMENSION_BY_MODEL[$model::class] ?? Dimension::Catalog;
    }
}
