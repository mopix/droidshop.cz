<?php

namespace App\Core\Domains\Jobs;

use App\Core\Domains\DomainCertProbe;
use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-runs DomainCertProbe for a single domain after a backoff delay, when
 * the previous attempt found no certificate yet (wave 2.1, task 6).
 *
 * Dispatched by DomainCertProbe itself carrying the next attempt number, so
 * the decision to keep retrying, or give up, stays entirely inside the
 * probe — this job is just the delayed re-entry into it. Carries the
 * domain id rather than the model (SerializesModels would re-fetch it
 * anyway) so a domain deleted between dispatch and run is a silent no-op.
 *
 * Tenant-aware by default (config/multitenancy.php): dispatched inside a
 * tenant's request or job context, it runs against that tenant when the
 * worker picks it up. The periodic sweep (task 8) dispatches this inside
 * runAs() per tenant.
 */
class ProbeDomainCertJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $domainId, private readonly int $attempt) {}

    public function domainId(): int
    {
        return $this->domainId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function handle(DomainCertProbe $probe): void
    {
        $domain = Domain::find($this->domainId);

        if ($domain) {
            $probe->probe($domain, $this->attempt);
        }
    }
}
