<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * Registration is where the platform's contract with a tenant comes into
     * being, so it records the consent: both when it was given and which
     * wording was in force. A timestamp alone cannot answer the second
     * question once the terms change.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
            'terms_accepted_at' => now(),
            'terms_version' => config('legal.terms_version'),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('onboarding.create', absolute: false));
    }
}
