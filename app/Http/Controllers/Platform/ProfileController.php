<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdatePlatformPasswordRequest;
use App\Http\Requests\Platform\UpdatePlatformProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform administrator's own account.
 *
 * Separate from the tenant ProfileController rather than shared: the two sit
 * on different guards and different tables, and the one thing they would have
 * in common — "edit your name and e-mail" — is the least interesting part.
 * What differs is what matters: this account has two-factor authentication,
 * and it cannot delete itself.
 *
 * There is deliberately no "delete account" here. A superadmin removing their
 * own row could leave the platform with no administrator at all, and nothing
 * in the app would notice until somebody needed to log in.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $admin = $request->user('platform');

        return Inertia::render('Platform/Profile/Edit', [
            'admin' => [
                'name' => $admin->name,
                'email' => $admin->email,
                'twoFactorConfirmedAt' => $admin->two_fa_confirmed_at?->toIso8601String(),
                'lastLoginAt' => $admin->last_login_at?->toIso8601String(),
            ],
            // Shown once, right after they are generated, and never again —
            // they are stored hashed.
            'recoveryCodes' => session('recoveryCodes'),
        ]);
    }

    public function update(UpdatePlatformProfileRequest $request): RedirectResponse
    {
        $admin = $request->user('platform');

        $admin->fill($request->safe()->only(['name', 'email']));
        $admin->save();

        return back()->with('success', 'Údaje byly uloženy.');
    }

    public function updatePassword(UpdatePlatformPasswordRequest $request): RedirectResponse
    {
        $admin = $request->user('platform');

        $admin->password = $request->string('password')->toString();
        $admin->save();

        return back()->with('success', 'Heslo bylo změněno.');
    }

    /**
     * Issues a fresh set of recovery codes, invalidating the old ones.
     *
     * Gated on the current password even though the admin is already signed
     * in and past the 2FA challenge: this is the one action that hands over a
     * way around that challenge, so an unattended session must not be enough.
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $admin = $request->user('platform');

        $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($request->string('current_password')->toString(), $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Zadané heslo není správné.',
            ]);
        }

        // Defence in depth: EnsurePlatformTwoFactor already redirects an
        // admin without confirmed 2FA to the setup screen, so this branch is
        // not reachable over HTTP. It stays because issuing codes for an
        // unconfigured second factor would be meaningless either way.
        if (! $admin->hasConfirmedTwoFactor()) {
            throw ValidationException::withMessages([
                'current_password' => 'Nejdřív dokončete nastavení dvoufaktorového ověření.',
            ]);
        }

        $codes = $admin->generateRecoveryCodes();

        return back()->with('recoveryCodes', $codes);
    }
}
