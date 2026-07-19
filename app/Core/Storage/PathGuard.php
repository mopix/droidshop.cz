<?php

namespace App\Core\Storage;

use App\Core\Storage\Exceptions\UnsafePath;

/**
 * Validates and normalises storage paths (spec §15.1 — FileStorage enforces
 * the tenant prefix).
 *
 * The whole point is that a caller cannot, by any spelling of a path, reach
 * outside tenants/{id}/. Anything that even looks like an attempt is rejected.
 */
class PathGuard
{
    /**
     * Normalises a relative path or throws if it could escape.
     *
     * @throws UnsafePath
     */
    public function clean(string $path): string
    {
        if ($path === '') {
            throw UnsafePath::for($path);
        }

        // A null byte can truncate the path at the filesystem layer, so a
        // "safe.jpg\0../../etc" becomes "safe.jpg" to this code but something
        // else to the OS. Reject outright.
        if (str_contains($path, "\0")) {
            throw UnsafePath::for($path);
        }

        // Backslashes are separators on some targets; treat them as suspicious
        // rather than reasoning about every platform.
        if (str_contains($path, '\\')) {
            throw UnsafePath::for($path);
        }

        // Percent signs mean someone passed a URL-encoded path where a plain
        // key was expected (e.g. %2e%2e for ..). Storage keys are generated
        // internally and never contain %, so reject rather than risk a decode
        // happening downstream.
        if (str_contains($path, '%')) {
            throw UnsafePath::for($path);
        }

        // An absolute path is a caller mistake, not a key. Reject rather than
        // silently reinterpret /etc/passwd as a relative key under the tenant.
        if (str_starts_with($path, '/')) {
            throw UnsafePath::for($path);
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            // Empty segment (//), current dir, or any segment that is or
            // contains a parent reference. Also catches the %2e%2e style, which
            // is not decoded here but must never appear literally as "..".
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '..')) {
                throw UnsafePath::for($path);
            }
        }

        return implode('/', $segments);
    }

    /**
     * The tenant-scoped key for a relative path.
     *
     * @throws UnsafePath
     */
    public function prefixed(int $tenantId, string $path): string
    {
        return "tenants/{$tenantId}/".$this->clean($path);
    }
}
