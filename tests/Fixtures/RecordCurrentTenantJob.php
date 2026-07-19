<?php

namespace Tests\Fixtures;

use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes whichever tenant is current when it runs to a plain file.
 *
 * A file rather than cache or the database on purpose: cache keys are
 * tenant-prefixed and database rows are tenant-scoped, so either would be
 * measuring the thing under test with the thing under test.
 */
class RecordCurrentTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $path) {}

    public function handle(TenantContext $context): void
    {
        file_put_contents($this->path, (string) ($context->id() ?? 'none'));
    }
}
