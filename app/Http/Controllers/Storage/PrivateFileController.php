<?php

namespace App\Http\Controllers\Storage;

use App\Core\Storage\FileStorage;
use App\Core\Storage\PathGuard;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a private tenant file behind a signed, tenant-checked URL.
 *
 * Two independent gates. The `signed` middleware proves the URL was minted by
 * us and has not expired. This controller then proves the file belongs to the
 * tenant whose context is current, so a signed URL for tenant A's file is
 * still useless when the request resolves to tenant B.
 */
class PrivateFileController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PathGuard $guard,
    ) {}

    public function __invoke(Request $request, int $tenant, string $path): StreamedResponse
    {
        $current = $this->context->id();

        // The signed URL names its tenant; the request context names the tenant
        // of the host it arrived on. They must agree, or a leaked URL would
        // work from another shop.
        if ($current === null || $current !== $tenant) {
            abort(404);
        }

        // Re-clean: the signature covers the path, but a defence that trusts a
        // signed value it never re-checks is one bug away from a hole.
        $key = $this->guard->prefixed($tenant, $path);

        $disk = Storage::disk(FileStorage::PRIVATE_DISK);

        if (! $disk->exists($key)) {
            abort(404);
        }

        return $disk->response($key);
    }
}
