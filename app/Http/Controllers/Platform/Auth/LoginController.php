<?php

namespace App\Http\Controllers\Platform\Auth;

use App\Http\Requests\Platform\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Superadmin login on the platform host.
 *
 * Authentication only gets the admin to the 2FA gate; the platform.2fa
 * middleware decides whether they may go further. Rate limiting and lockout
 * live in the form request (spec §15.4).
 */
class LoginController
{
    public function show(): Response
    {
        return Inertia::render('Platform/Auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        Auth::guard('platform')->user()->forceFill([
            'last_login_at' => now(),
        ])->save();

        return redirect()->intended(route('platform.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
