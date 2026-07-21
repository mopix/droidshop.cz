<?php

namespace App\Core\Documents\Exceptions;

use RuntimeException;

class DocumentIssuanceUnavailable extends RuntimeException
{
    public static function moduleOff(): self
    {
        return new self('The docs module is not active for this tenant; no document can be issued.');
    }
}
