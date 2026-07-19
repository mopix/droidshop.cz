<?php

namespace Tests\Fixtures;

use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * A platform-level job: billing runs, tenant purges, superadmin reports.
 *
 * Implementing NotTenantAware is mandatory for these. Without it the queue
 * discards any job dispatched with no tenant current, silently.
 */
class RecordPlatformJob implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $path) {}

    public function handle(TenantContext $context): void
    {
        file_put_contents($this->path, (string) ($context->id() ?? 'none'));
    }
}
