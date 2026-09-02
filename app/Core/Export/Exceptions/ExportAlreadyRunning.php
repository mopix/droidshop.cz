<?php

namespace App\Core\Export\Exceptions;

use App\Models\Tenant;
use RuntimeException;

class ExportAlreadyRunning extends RuntimeException
{
    public static function for(Tenant $tenant): self
    {
        return new self('Export dat pro e-shop '.$tenant->name.' už běží. Počkejte, než doběhne.');
    }
}
