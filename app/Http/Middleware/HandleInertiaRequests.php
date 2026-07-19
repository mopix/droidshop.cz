<?php

namespace App\Http\Middleware;

use App\Core\Platform\Impersonation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $impersonation = app(Impersonation::class);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'recoveryCodes' => fn () => $request->session()->get('recoveryCodes'),
            ],
            // Drives the "you are impersonating" banner so a superadmin never
            // forgets they are acting as someone else.
            'impersonating' => $impersonation->isActive() ? [
                'user_id' => $impersonation->impersonatedUserId(),
                'admin_id' => $impersonation->impersonatorId(),
            ] : null,
        ];
    }
}
