<?php

namespace App\Core\Settings\Exceptions;

use InvalidArgumentException;

class InvalidSetting extends InvalidArgumentException
{
    public static function unknownKey(string $module, string $key): self
    {
        return new self("Module [{$module}] declares no setting named [{$key}].");
    }

    public static function failedValidation(string $module, string $key, string $error): self
    {
        return new self("Setting [{$module}.{$key}] is invalid: {$error}");
    }
}
