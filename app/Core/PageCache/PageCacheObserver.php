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
 * ProductOption/ProductOptionValue are here because they render straight
 * into the cached product page (variant-picker.blade.php prints axis and
 * value labels), even though the variant matrix's rows (ProductVariant) were
 * already covered.
 *
 * ProductImage is here because an image is not decoration on one page: it is
 * the gallery on the product page, og:image, the tile on every product card
 * in category/homepage/search listings, and IMGURL in both feeds. Without it
 * an uploaded photo appeared nowhere for ten minutes and in no feed for an
 * hour.
 *
 * Three query-builder writes are the deliberate exceptions: they update
 * through the query builder and fire no Eloquent event, so each bumps for
 * itself instead of relying on this observer.
 * - EloquentProductCatalog::decrementStock (stock write-off). See wave 3.0
 *   spec, decision 8.
 * - CategoryTree::reorder (bulk positional update of category siblings).
 * - ProductImageService::reorder (bulk positional update of one product's
 *   images). Its sibling ProductImageService::makeMain() also writes through
 *   the builder, but is already covered: the very next statement saves the
 *   newly-main image through Eloquent, which this observer does see.
 *
 * Both are documented at their own call site so the fix stays next to the
 * write it fixes; this class does not call Generations for either.
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
        'Modules\\Products\\Models\\ProductOption' => Dimension::Catalog,
        'Modules\\Products\\Models\\ProductOptionValue' => Dimension::Catalog,
        'Modules\\Products\\Models\\ProductImage' => Dimension::Catalog,
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
