<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The nájemce's accounting export (wave 2.11). Reads only through the kernel
 * DocumentLedger contract — never the docs module's Eloquent model.
 */
class AccountingExportController
{
    public function index(Request $request): Response
    {
        abort_unless($request->user('web')?->can('accounting.export'), 403);

        return Inertia::render('Modules/Accounting/Index', [
            'formats' => [
                ['key' => 'pohoda', 'label' => 'Pohoda XML'],
                ['key' => 'isdoc', 'label' => 'ISDOC (ZIP)'],
            ],
            'maxDocuments' => (int) config('accounting.max_documents'),
        ]);
    }
}
