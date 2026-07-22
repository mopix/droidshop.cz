<?php

namespace App\Core\Documents;

use App\Core\Documents\Contracts\DocumentBook;
use App\Core\Documents\Contracts\DocumentView;
use Illuminate\Support\Collection;

/**
 * The kernel's own answer to DocumentBook, bound by default
 * (App\Providers\AppServiceProvider) and overridden by
 * Modules\Docs\Providers\ModuleProvider whenever that module is actually
 * part of the deploy.
 *
 * Every order looks like it has never had a document issued for it through
 * this implementation — no documents on any order detail page. That is what
 * makes app(DocumentBook::class) safe to call unconditionally instead of
 * throwing a container resolution error on a deploy without the docs module.
 */
final class NullDocumentBook implements DocumentBook
{
    public function forOrder(string $orderUuid): Collection
    {
        /** @var Collection<int, DocumentView> */
        return new Collection;
    }
}
