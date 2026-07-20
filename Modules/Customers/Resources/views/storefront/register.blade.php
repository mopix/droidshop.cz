@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Registrace</h1>

    <form method="POST" action="{{ route('storefront.customers.register.store') }}" class="mt-6 max-w-md space-y-4">
        @csrf

        <div>
            <label for="first_name" class="block text-sm font-medium">Jméno</label>
            <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" required autocomplete="given-name"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('first_name') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="last_name" class="block text-sm font-medium">Příjmení</label>
            <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}" required autocomplete="family-name"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('last_name') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-medium">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium">Telefon <span class="font-normal text-slate-500">(nepovinné)</span></label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('phone') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">Heslo</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('password') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">Heslo znovu</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </div>

        <div>
            <label for="terms" class="flex items-start gap-2 text-sm">
                <input id="terms" name="terms" type="checkbox" value="1" required class="mt-1">
                <span>Souhlasím s obchodními podmínkami a zpracováním osobních údajů</span>
            </label>
            @error('terms') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Založit účet</button>
    </form>

    <p class="mt-4 text-sm">Už účet máte? <a href="{{ route('storefront.customers.login') }}" class="underline">Přihlaste se</a>.</p>
@endsection
