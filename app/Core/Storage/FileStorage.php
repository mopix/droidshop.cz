<?php

namespace App\Core\Storage;

use App\Core\Limits\LimitsService;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The only supported way for a module to touch stored files (spec §15.1).
 *
 * Every path is forced under tenants/{id}/ by PathGuard, so a module cannot
 * reach another tenant's files whatever path it passes. Public files (product
 * images) go on a web-served disk; private files (invoices, exports) go on a
 * disk with no URL, reachable only through a signed, tenant-checked route.
 *
 * The underlying disks are local for the MVP. A module never names a disk, so
 * moving either to S3 later is a config change here, nothing more.
 */
class FileStorage
{
    public const PUBLIC_DISK = 'tenant_public';

    public const PRIVATE_DISK = 'tenant_private';

    public const SIGNED_ROUTE = 'storage.private';

    public function __construct(
        private readonly TenantContext $context,
        private readonly PathGuard $guard,
    ) {}

    /**
     * Stores a public file and returns its tenant-relative key.
     */
    public function putPublic(string $path, mixed $contents): string
    {
        // key() resolves the tenant first, so a missing context fails as
        // MissingTenantContext rather than as a limit error.
        $key = $this->key($path);
        $this->guardStorageLimit($contents);
        $this->publicDisk()->put($key, $contents);

        return $path;
    }

    /**
     * Stores a private file and returns its tenant-relative key.
     */
    public function putPrivate(string $path, mixed $contents): string
    {
        $key = $this->key($path);
        $this->guardStorageLimit($contents);
        $this->privateDisk()->put($key, $contents);

        return $path;
    }

    /**
     * Refuses the write if it would take the tenant over their storage limit.
     *
     * LimitsService is resolved lazily, not injected: the storage_mb counter
     * depends on FileStorage, and taking LimitsService in the constructor would
     * close that loop. Read at call time, the cycle never forms.
     */
    private function guardStorageLimit(mixed $contents): void
    {
        $bytes = is_string($contents) ? strlen($contents) : 0;

        // Conservative: round the new file up to whole MB of headroom needed.
        $deltaMb = (int) ceil($bytes / (1024 * 1024));

        $result = app(LimitsService::class)->check('storage_mb', $deltaMb);

        if (! $result->allowed()) {
            throw new Exceptions\StorageLimitExceeded($result->message);
        }
    }

    public function get(string $path, bool $private = true): string
    {
        return $this->disk($private)->get($this->key($path));
    }

    public function exists(string $path, bool $private = true): bool
    {
        return $this->disk($private)->exists($this->key($path));
    }

    public function size(string $path, bool $private = true): int
    {
        return $this->disk($private)->size($this->key($path));
    }

    public function delete(string $path, bool $private = true): void
    {
        $this->disk($private)->delete($this->key($path));
    }

    /**
     * A directly web-served URL for a public file.
     */
    public function publicUrl(string $path): string
    {
        return $this->publicDisk()->url($this->key($path));
    }

    /**
     * A temporary signed URL for a private file.
     *
     * The URL carries the tenant and the relative path; the serving route
     * re-checks both, so a leaked URL is useless once it expires and useless
     * to a different tenant even before that.
     */
    public function signedUrl(string $path, int $ttl = 300): string
    {
        // Validate the path now, so a bad key fails here and not at serve time.
        $this->guard->clean($path);

        $tenant = $this->context->current();

        if ($tenant === null) {
            throw MissingTenantContext::forModel('file storage');
        }

        // The URL must live on the tenant's own domain, not the platform's:
        // that is where the file resolves, and signedUrl may be called from a
        // queue job with no request host to borrow. Laravel's signature covers
        // the host, so the root is forced before signing, not swapped after.
        $domain = $tenant->primaryDomain?->domain;

        if ($domain === null) {
            return URL::temporarySignedRoute(self::SIGNED_ROUTE, now()->addSeconds($ttl), [
                'tenant' => $tenant->id,
                'path' => $path,
            ]);
        }

        $previousRoot = URL::to('/');
        URL::forceRootUrl('https://'.$domain);

        try {
            return URL::temporarySignedRoute(self::SIGNED_ROUTE, now()->addSeconds($ttl), [
                'tenant' => $tenant->id,
                'path' => $path,
            ]);
        } finally {
            URL::forceRootUrl($previousRoot);
        }
    }

    /**
     * Removes everything belonging to the current tenant, from both disks.
     * Used by the tenant purge job (spec §6.0 AK).
     */
    public function deleteTenantPrefix(): void
    {
        $prefix = 'tenants/'.$this->tenantId();

        $this->publicDisk()->deleteDirectory($prefix);
        $this->privateDisk()->deleteDirectory($prefix);
    }

    /**
     * Total bytes stored for the current tenant across both disks.
     */
    public function tenantUsageBytes(): int
    {
        $prefix = 'tenants/'.$this->tenantId();
        $total = 0;

        foreach ([$this->publicDisk(), $this->privateDisk()] as $disk) {
            foreach ($disk->allFiles($prefix) as $file) {
                $total += $disk->size($file);
            }
        }

        return $total;
    }

    private function key(string $path): string
    {
        return $this->guard->prefixed($this->tenantId(), $path);
    }

    private function tenantId(): int
    {
        $id = $this->context->id();

        if ($id === null) {
            throw MissingTenantContext::forModel('file storage');
        }

        return $id;
    }

    private function disk(bool $private): Filesystem
    {
        return $private ? $this->privateDisk() : $this->publicDisk();
    }

    private function publicDisk(): Filesystem
    {
        return Storage::disk(self::PUBLIC_DISK);
    }

    private function privateDisk(): Filesystem
    {
        return Storage::disk(self::PRIVATE_DISK);
    }
}
