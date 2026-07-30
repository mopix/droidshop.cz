<?php

namespace Modules\Accounting\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Support\AccountingFormats;
use Modules\Accounting\Support\IsdocFormat;
use Modules\Accounting\Support\PohodaXmlFormat;

/**
 * The accounting module binds nothing into the kernel: it only reads issued
 * documents through the DocumentLedger contract that already exists. Format
 * registration lives in AccountingFormats (Task 4), resolved on demand.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccountingFormats::class, fn () => new AccountingFormats([
            new PohodaXmlFormat,
            new IsdocFormat,
        ]));
    }
}
