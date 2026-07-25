@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Přihlášení</h1>

        @if (session('status'))
            <p role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                {{ session('status') }}
            </p>
        @endif

        <form method="POST" action="{{ route('storefront.customers.login.store') }}" class="card mt-6 space-y-4 p-6">
            @csrf

            <div>
                <label for="email" class="field-label">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                       class="field-input">
                @error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="field-label">Heslo</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="field-input">
                @error('password') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="remember" class="flex items-center gap-2 text-sm text-slate-700">
                    <input id="remember" name="remember" type="checkbox" value="1"
                           class="rounded border-slate-300 text-brand focus:ring-brand">
                    <span>Zapamatovat přihlášení</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary w-full">Přihlásit se</button>
        </form>

        <p class="mt-4 text-sm">
            <a href="{{ route('storefront.customers.password.request') }}" class="text-slate-600 underline hover:text-brand">Zapomněli jste heslo?</a>
        </p>

        <p class="mt-2 text-sm text-slate-600">
            Nemáte účet? <a href="{{ route('storefront.customers.register') }}" class="text-brand underline">Zaregistrujte se</a>.
        </p>
    </div>
@endsection
