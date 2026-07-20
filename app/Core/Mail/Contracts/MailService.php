<?php

namespace App\Core\Mail\Contracts;

use App\Core\Mail\Exceptions\MailLimitReached;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;

/**
 * The only supported way the platform sends e-mail (spec §15.1).
 *
 * Modules never touch Mail::send() directly. Going through here is what makes
 * three things true at once: the message counts against the tenant's plan, it
 * lands in a log the nájemce can inspect, and it goes out under the tenant's
 * name rather than the platform's.
 */
interface MailService
{
    /**
     * Queue a message for delivery on behalf of a tenant.
     *
     * The mailable's build()/envelope() must be side-effect free: the
     * platform calls it once here to read the subject for the log entry,
     * and again later when the queued job actually delivers it.
     *
     * @param  string|array<int, string>  $to
     *
     * @throws MissingTenantContext when no tenant is given or current
     * @throws MailLimitReached when the plan's monthly cap is exhausted
     */
    public function send(Mailable $mailable, string|array $to, ?Tenant $tenant = null): MailMessage;
}
