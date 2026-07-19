<?php

namespace App\Http\Controllers;

use App\Core\Platform\Impersonation;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant side of impersonation (spec §6.12).
 *
 * Reached only through a signed URL minted by the superadmin. Establishes the
 * impersonated session on the tenant's own host, where it belongs, and audits
 * both the start and the end with impersonated_by set.
 */
class ImpersonationController
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly TenantContext $context,
        private readonly AuditLog $audit,
    ) {}

    public function begin(Request $request, int $user, int $admin): RedirectResponse
    {
        $tenant = $this->context->current();
        abort_if($tenant === null, 404);

        $target = User::findOrFail($user);

        // Re-check on this side too: the signature proves the URL is ours, this
        // proves the target still belongs to the tenant it is being used on.
        abort_unless($tenant->users()->whereKey($target->id)->exists(), 403);

        $platformAdmin = PlatformAdmin::findOrFail($admin);

        Auth::guard('web')->login($target);
        $this->impersonation->start($platformAdmin, $target, $tenant);

        // impersonated_by is stamped automatically by AuditLog from the now
        // active impersonation.
        $this->audit->log('impersonation.started', $target);

        return redirect('/');
    }

    public function end(Request $request): RedirectResponse
    {
        $target = Auth::guard('web')->user();

        if ($target instanceof User) {
            $this->audit->log('impersonation.stopped', $target);
        }

        $this->impersonation->stop();
        Auth::guard('web')->logout();

        return redirect('/');
    }
}
