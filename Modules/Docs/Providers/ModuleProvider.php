<?php

namespace Modules\Docs\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Scaffold only (wave 1.5, Task 2). The kernel's NullDocumentIssuer stays
 * bound until Task 3 adds InvoiceIssuer and overrides DocumentIssuer here —
 * binding it now would reference a class that does not exist yet and break
 * module registration for every request.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
