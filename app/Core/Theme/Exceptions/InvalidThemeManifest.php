<?php

namespace App\Core\Theme\Exceptions;

use RuntimeException;

/**
 * A theme.json that cannot be trusted.
 *
 * Fatal when the registry is built, never when a storefront page renders: a
 * theme whose manifest is wrong is a deploy mistake, and letting it through
 * half-parsed would put a broken look in front of a tenant's customers with
 * no clue where it came from. A theme directory that simply disappeared is a
 * different case and falls back to the default (see ThemeRegistry::find).
 */
class InvalidThemeManifest extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public static function forPath(string $path, array $errors): self
    {
        return new self("Invalid theme manifest [{$path}]:\n  - ".implode("\n  - ", $errors));
    }

    public static function unreadable(string $path): self
    {
        return new self("Theme manifest [{$path}] is missing or is not valid JSON.");
    }
}
