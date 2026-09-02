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

    /**
     * Where platform-generated artefacts live inside the tenant's own prefix.
     *
     * They sit there so `PathGuard` and `signedUrl()` apply unchanged, but they
     * are not the tenant's files: they do not count towards the storage limit
     * and they are not copied into an export. Without the second rule an export
     * would archive the previous export, doubling on every run.
     */
    public const ARTEFACT_PREFIXES = ['exports/'];

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
    /**
     * A root-relative URL, correct on every host the shop answers on.
     *
     * Relative rather than absolute because a tenant may be reached on a
     * subdomain, on its own domain, over http in development and over https in
     * production — and the file is served from the same origin in all four
     * cases. Anything that leaves the page (a feed, an og:image) needs
     * publicUrlAbsolute() instead.
     */
    public function publicUrl(string $path): string
    {
        return $this->publicDisk()->url($this->key($path));
    }

    /**
     * The same file, addressed absolutely, for consumers that are not the page
     * itself: comparison-shopping feeds and the og:image a social network
     * fetches.
     *
     * Built from the current request, so it carries the host the visitor (or
     * the crawler) actually used. Per tenant, not per visitor, so it is safe
     * inside a cached page.
     */
    public function publicUrlAbsolute(string $path): string
    {
        return url($this->publicUrl($path));
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
    /**
     * Every file the current tenant owns on one disk, as tenant-relative paths.
     *
     * Used by the export (spec §4.2 pojistka 4), which needs the file tree the
     * same way it needs the tables.
     *
     * @return list<string>
     */
    public function tenantFiles(bool $private = true): array
    {
        $prefix = 'tenants/'.$this->tenantId().'/';
        $disk = $this->disk($private);

        return array_values(array_filter(
            array_map(
                fn (string $key): string => substr($key, strlen($prefix)),
                array_filter(
                    $disk->allFiles(rtrim($prefix, '/')),
                    fn (string $key): bool => str_starts_with($key, $prefix),
                ),
            ),
            fn (string $path): bool => ! self::isPlatformArtefact($path),
        ));
    }

    /**
     * A read stream for a stored file, or null when the file is gone.
     *
     * Returns null rather than throwing because a database row can outlive its
     * file, and the export must not abort over one missing image.
     *
     * @return resource|null
     */
    public function readStream(string $path, bool $private = true)
    {
        $disk = $this->disk($private);
        $key = $this->key($path);

        if (! $disk->exists($key)) {
            return null;
        }

        $stream = $disk->readStream($key);

        return is_resource($stream) ? $stream : null;
    }

    /**
     * Stores a private file without charging it to the tenant's storage limit.
     *
     * Only for platform-generated artefacts the tenant did not upload — today
     * that is the data export, which is a copy of bytes already counted once.
     * Metering it would mean the tenant closest to their quota is the one who
     * cannot get their data out, which inverts what the limit is for.
     */
    public function putPrivateUnmetered(string $path, mixed $contents): string
    {
        $this->privateDisk()->put($this->key($path), $contents);

        return $path;
    }

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
                // Skipped for the same reason they are unmetered on write: a
                // tenant is not charged storage for the copy of their own data
                // we generated for them.
                if (self::isPlatformArtefact(substr($file, strlen($prefix) + 1))) {
                    continue;
                }

                $total += $disk->size($file);
            }
        }

        return $total;
    }

    /**
     * Is this tenant-relative path a platform artefact rather than the
     * tenant's own file?
     */
    public static function isPlatformArtefact(string $path): bool
    {
        foreach (self::ARTEFACT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
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
