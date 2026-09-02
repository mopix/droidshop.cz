<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Enums\TenantRole;
use App\Core\Export\Exceptions\ExportAlreadyRunning;
use App\Core\Export\ExportRequests;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\JobLogEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Download all my data" for the shop owner (spec §4.2 pojistka 4).
 *
 * Owner only, and deliberately not routed through `TenantPermissions`: that
 * service answers from module manifests, and the export is a kernel capability
 * no module declares — asking it would refuse everyone. The archive contains
 * every customer's personal data, so `TenantRole::Staff` does not get it even
 * once phase 2 lands.
 */
class DataExportController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ExportRequests $requests,
        private readonly FileStorage $files,
    ) {}

    public function show(Request $request): Response
    {
        $this->authoriseOwner($request);

        $tenant = $this->context->current();
        $latest = $this->requests->latest($tenant);

        return Inertia::render('Tenant/Settings/DataExport', [
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'status' => $latest->status,
                'running' => $latest->isRunning(),
                'createdAt' => $latest->created_at?->toIso8601String(),
                'finishedAt' => $latest->finished_at?->toIso8601String(),
                'report' => $latest->report,
                'downloadUrl' => $this->downloadUrlFor($latest),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authoriseOwner($request);

        try {
            $this->requests->start($this->context->current());
        } catch (ExportAlreadyRunning $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }

        return back()->with('success', 'Export dat byl zařazen. Až doběhne, objeví se tu odkaz ke stažení.');
    }

    /**
     * Streams the archive to the owner.
     *
     * Deliberately NOT the platform's signed-URL route (`storage.private`).
     * That route proves the link is ours and unexpired, but it carries no
     * authentication — it is a capability URL, which is a fair trade for a
     * single invoice and a bad one for an archive holding every customer the
     * shop has. A signed link leaks through browser history, a Referer header
     * and a shared screenshot; this one is worthless without the session.
     */
    public function download(Request $request, JobLogEntry $job): StreamedResponse
    {
        $this->authoriseOwner($request);

        $tenant = $this->context->current();

        // Route model binding resolves before the tenant scope can help here,
        // so ownership is checked explicitly: a job id from another shop must
        // not stream that shop's archive.
        abort_unless(
            $job->tenant_id === $tenant->id
                && $job->type === JobLogEntry::TYPE_EXPORT
                && $job->status === JobLogEntry::STATUS_FINISHED,
            404,
        );

        $path = $job->report['path'] ?? null;

        abort_unless(is_string($path) && $this->files->exists($path), 404);

        return Storage::disk(FileStorage::PRIVATE_DISK)->download(
            'tenants/'.$tenant->id.'/'.$path,
            'export-'.$tenant->id.'-'.$job->created_at?->format('Y-m-d').'.zip',
        );
    }

    private function downloadUrlFor(JobLogEntry $entry): ?string
    {
        $path = $entry->report['path'] ?? null;

        if ($entry->status !== JobLogEntry::STATUS_FINISHED || ! is_string($path)) {
            return null;
        }

        if (! $this->files->exists($path)) {
            return null;
        }

        return route('admin.export.download', ['job' => $entry->id]);
    }

    private function authoriseOwner(Request $request): void
    {
        abort_unless(
            $request->user('web')?->roleIn($this->context->current()) === TenantRole::Owner,
            403,
        );
    }
}
