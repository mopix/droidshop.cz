<?php

namespace App\Core\Export\Exceptions;

use RuntimeException;

class ExportFailed extends RuntimeException
{
    public static function cannotWrite(string $path): self
    {
        return new self('Export nelze zapsat: '.$path);
    }

    public static function cannotEncode(string $table): self
    {
        return new self('Tabulku '.$table.' nelze převést do JSON (nejspíš neplatné UTF-8 v datech).');
    }
}
