<?php

namespace App\Core\Storage\Exceptions;

use InvalidArgumentException;

/**
 * A storage path that could escape the tenant's prefix.
 *
 * Rejected, never sanitised into something "close enough": a path that tried
 * to traverse is a bug or an attack, and quietly rewriting it would hide both.
 */
class UnsafePath extends InvalidArgumentException
{
    public static function for(string $path): self
    {
        return new self("Unsafe storage path [{$path}]: paths must be relative and stay within the tenant prefix.");
    }
}
