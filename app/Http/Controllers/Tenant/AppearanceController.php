<?php

namespace App\Http\Controllers\Tenant;

use App\Core\PageCache\Generations;
use App\Core\Storage\FileStorage;
use App\Core\Tenancy\TenantContext;
use App\Core\Theme\Contrast;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateAppearanceRequest;
use App\Models\TenantTheme;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant-admin brand settings: primary/accent color and logo/favicon
 * (storefront theme, wave 2.2). Core, not a module — every shop can brand
 * itself regardless of which catalog/checkout modules it runs.
 *
 * The theme row is looked up strictly from the current request's tenant
 * (context->current()), never from a route-bound id, so there is no id a
 * tenant could swap in the URL to reach another tenant's theme.
 */
class AppearanceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FileStorage $files,
        private readonly Generations $generations,
    ) {}

    public function edit(): Response
    {
        $theme = $this->currentTheme();
        $primary = $theme?->primaryColor() ?? TenantTheme::DEFAULT_PRIMARY;
        $accent = $theme?->accentColor() ?? TenantTheme::DEFAULT_ACCENT;

        return Inertia::render('Tenant/Appearance', [
            'appearance' => [
                'primary_color' => $primary,
                'accent_color' => $accent,
                'logo_url' => $theme?->logo_path !== null ? $this->files->publicUrl($theme->logo_path) : null,
                'favicon_url' => $theme?->favicon_path !== null ? $this->files->publicUrl($theme->favicon_path) : null,
                'contrast_ratio' => round(Contrast::ratio($primary, '#ffffff'), 2),
            ],
        ]);
    }

    public function update(UpdateAppearanceRequest $request): RedirectResponse
    {
        $tenant = $this->context->current();

        // Explicit whitelist into a $guarded=[] model — never $request->all().
        $data = [
            'primary_color' => $request->validated('primary_color'),
            'accent_color' => $request->validated('accent_color'),
        ];

        if ($request->hasFile('logo')) {
            $extension = $request->file('logo')->extension();
            $path = "theme/logo.{$extension}";
            $this->files->putPublic($path, file_get_contents($request->file('logo')->getRealPath()));
            $data['logo_path'] = $path;
        }

        if ($request->hasFile('favicon')) {
            $extension = $request->file('favicon')->extension();
            $path = "theme/favicon.{$extension}";
            $this->files->putPublic($path, file_get_contents($request->file('favicon')->getRealPath()));
            $data['favicon_path'] = $path;
        }

        TenantTheme::updateOrCreate(['tenant_id' => $tenant->id], $data);

        return back()->with('success', 'Vzhled uložen.');
    }

    /**
     * Escape hatch for a tenant who changed something the automatic
     * invalidation did not catch and does not want to wait out the TTL.
     * Bumps all three generations (catalog/content/theme) at once.
     */
    public function flushCache(): RedirectResponse
    {
        $tenant = $this->context->current();

        $this->generations->bumpAll($tenant);

        return back()->with('success', 'Cache e-shopu byla vymazána.');
    }

    public function destroyLogo(): RedirectResponse
    {
        $theme = $this->currentTheme();

        if ($theme?->logo_path !== null) {
            $this->files->delete($theme->logo_path, private: false);
            $theme->update(['logo_path' => null]);
        }

        return back()->with('success', 'Logo bylo odebráno.');
    }

    public function destroyFavicon(): RedirectResponse
    {
        $theme = $this->currentTheme();

        if ($theme?->favicon_path !== null) {
            $this->files->delete($theme->favicon_path, private: false);
            $theme->update(['favicon_path' => null]);
        }

        return back()->with('success', 'Favicon byl odebrán.');
    }

    private function currentTheme(): ?TenantTheme
    {
        $tenant = $this->context->current();

        return TenantTheme::where('tenant_id', $tenant->id)->first();
    }
}
