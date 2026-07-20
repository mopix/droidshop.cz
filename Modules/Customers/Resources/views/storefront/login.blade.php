@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Přihlášení</h1>

    <form method="POST" action="{{ route('storefront.customers.login.store') }}" class="mt-6 max-w-md space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">Heslo</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('password') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="remember" class="flex items-center gap-2 text-sm">
                <input id="remember" name="remember" type="checkbox" value="1">
                <span>Zapamatovat přihlášení</span>
            </label>
        </div>

        <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Přihlásit se</button>
    </form>

    <p class="mt-4 text-sm">
        Nemáte účet? <a href="{{ route('storefront.customers.register') }}" class="underline">Zaregistrujte se</a>.
    </p>

    {{-- "Zapomněli jste heslo?" link lands with the route in Task 3
         (storefront.customers.password.request) — a non-existent named route
         would make this page 500 on every render until then. --}}
@endsection
