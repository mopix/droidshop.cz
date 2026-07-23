<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Domains\DomainCertProbe;
use App\Core\Domains\DomainVerifier;
use App\Core\Enums\DomainType;
use App\Core\Enums\SslStatus;
use App\Core\Tenancy\DomainTenantFinder;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AddCustomDomainRequest;
use App\Models\Domain;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant-admin self-service for attaching a custom domain (wave 2.1, task 9).
 *
 * One custom domain per tenant for this wave — see AddCustomDomainRequest.
 * Ownership proof (DNS TXT + routing) and certificate issuance are owned by
 * DomainVerifier/DomainCertProbe respectively; this controller only ever
 * triggers them and reports back what they left on the row.
 */
class DomainController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DomainVerifier $verifier,
        private readonly DomainCertProbe $probe,
        private readonly DomainTenantFinder $finder,
    ) {}

    public function edit(): Response
    {
        $tenant = $this->context->current();

        $subdomain = Domain::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', DomainType::Subdomain)
            ->value('domain');

        $custom = $this->customDomain();

        return Inertia::render('Tenant/Domain', [
            'subdomain' => $subdomain,
            'custom' => $custom === null ? null : [
                'domain' => $custom->domain,
                'ssl_status' => $custom->ssl_status->value,
                'verified' => $custom->isVerified(),
                'verification_error' => $custom->verification_error,
                'last_checked_at' => $custom->last_checked_at?->toIso8601String(),
            ],
            'instructions' => $custom === null ? null : [
                'txt_host' => config('platform.challenge_prefix').'.'.$custom->domain,
                'txt_value' => $custom->challenge_token,
                'cname_host' => $custom->domain,
                'cname_value' => config('platform.edge_host'),
                'a_value' => config('platform.server_ip'),
            ],
        ]);
    }

    public function store(AddCustomDomainRequest $request): RedirectResponse
    {
        $tenant = $this->context->current();

        try {
            Domain::create([
                'tenant_id' => $tenant->id,
                'domain' => $request->validated('domain'),
                'type' => DomainType::Custom,
                'is_primary' => false,
                'ssl_status' => SslStatus::None,
                'verified_at' => null,
                'challenge_token' => Str::random(40),
            ]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['domain' => 'Tuto doménu už používá jiný e-shop.']);
        }

        return back()->with('success', 'Doména přidána. Nastavte DNS záznamy a poté klikněte na Ověřit.');
    }

    public function verify(): RedirectResponse
    {
        $domain = $this->customDomain();

        if ($domain === null) {
            abort(404);
        }

        if ($domain->verified_at === null) {
            $this->verifier->verify($domain);
        } elseif ($domain->ssl_status !== SslStatus::Issued) {
            // Re-arm a stuck/errored cert probe: reset the terminal Error
            // state back to Pending before re-triggering, otherwise the
            // probe's own guard (never re-probe once terminal-ish) would
            // make this button a no-op.
            $domain->ssl_status = SslStatus::Pending;
            $domain->verification_error = null;
            $domain->save();

            $this->probe->probe($domain);
        }

        $domain->refresh();

        if ($domain->verification_error !== null) {
            return back()->with('error', $domain->verification_error);
        }

        return back()->with('success', $this->statusMessage($domain));
    }

    public function destroy(): RedirectResponse
    {
        $tenant = $this->context->current();
        $domain = $this->customDomain();

        if ($domain === null) {
            abort(404);
        }

        $wasPrimary = $domain->is_primary;

        if ($wasPrimary) {
            Domain::query()
                ->where('tenant_id', $tenant->id)
                ->where('type', DomainType::Subdomain)
                ->update(['is_primary' => true]);
        }

        $this->finder->forget($domain->domain);

        $subdomainHost = Domain::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', DomainType::Subdomain)
            ->value('domain');

        if ($wasPrimary && $subdomainHost !== null) {
            $this->finder->forget($subdomainHost);
        }

        $domain->delete();

        return back()->with('success', 'Doména odebrána.');
    }

    private function customDomain(): ?Domain
    {
        return Domain::query()
            ->where('tenant_id', $this->context->id())
            ->where('type', DomainType::Custom)
            ->first();
    }

    private function statusMessage(Domain $domain): string
    {
        return match (true) {
            $domain->ssl_status === SslStatus::Issued => 'Doména je aktivní, certifikát byl vydán.',
            $domain->verified_at !== null => 'Doména ověřena, čeká se na vydání certifikátu.',
            default => 'Ověřování zatím neproběhlo úspěšně, zkontrolujte DNS záznamy.',
        };
    }
}
