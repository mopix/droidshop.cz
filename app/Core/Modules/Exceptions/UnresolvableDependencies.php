<?php

namespace App\Core\Modules\Exceptions;

use RuntimeException;

class UnresolvableDependencies extends RuntimeException
{
    /**
     * @param  list<string>  $cycle
     */
    public static function cycle(array $cycle): self
    {
        return new self('Modules depend on each other in a cycle: '.implode(' -> ', $cycle).'.');
    }

    public static function missing(string $module, string $dependency): self
    {
        return new self("Module [{$module}] requires [{$dependency}], which is not installed.");
    }

    public static function versionMismatch(string $module, string $dependency, string $constraint, string $actual): self
    {
        return new self(
            "Module [{$module}] requires [{$dependency}] {$constraint}, but version {$actual} is installed."
        );
    }
}
