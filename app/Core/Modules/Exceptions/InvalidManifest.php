<?php

namespace App\Core\Modules\Exceptions;

use RuntimeException;

/**
 * A module.json that cannot be trusted.
 *
 * Always fatal for modules:sync. A half-written registry row is worse than a
 * missing one: the module would appear installable and fail later, somewhere
 * far from the actual mistake.
 */
class InvalidManifest extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function forPath(string $path, array $errors): self
    {
        $lines = [];

        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $lines[] = "  - {$field}: {$message}";
            }
        }

        return new self("Invalid module manifest [{$path}]:\n".implode("\n", $lines));
    }

    public static function unreadable(string $path): self
    {
        return new self("Module manifest [{$path}] is missing or is not valid JSON.");
    }
}
