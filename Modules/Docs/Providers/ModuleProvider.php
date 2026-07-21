<?php

namespace Modules\Docs\Providers;

use App\Core\Documents\Contracts\DocumentIssuer;
use Illuminate\Support\ServiceProvider;
use Modules\Docs\Services\InvoiceIssuer;

/**
 * Overrides the kernel's NullDocumentIssuer with the real issuer at deploy
 * level. The per-tenant "is the module active" question is answered at call
 * time by ShopModules inside InvoiceIssuer, not here — this binding is per
 * deploy, matching Modules\Orders\Providers\ModuleProvider.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DocumentIssuer::class, InvoiceIssuer::class);
    }
}
