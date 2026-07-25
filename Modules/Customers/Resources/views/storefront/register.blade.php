@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Registrace</h1>

        <form method="POST" action="{{ route('storefront.customers.register.store') }}" class="card mt-6 space-y-4 p-6">
            @csrf

            <div>
                <label for="first_name" class="field-label">Jméno</label>
                <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" required autocomplete="given-name"
                       class="field-input">
                @error('first_name') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="last_name" class="field-label">Příjmení</label>
                <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}" required autocomplete="family-name"
                       class="field-input">
                @error('last_name') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="email" class="field-label">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                       class="field-input">
                @error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="phone" class="field-label">Telefon <span class="font-normal text-slate-500">(nepovinné)</span></label>
                <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel"
                       class="field-input">
                @error('phone') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="field-label">Heslo</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                       class="field-input">
                @error('password') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="field-label">Heslo znovu</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                       class="field-input">
            </div>

            <div>
                <label for="terms" class="flex items-start gap-2 text-sm text-slate-700">
                    <input id="terms" name="terms" type="checkbox" value="1" required class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
                    <span>Souhlasím s obchodními podmínkami a zpracováním osobních údajů</span>
                </label>
                @error('terms') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full">Založit účet</button>
        </form>

        <p class="mt-4 text-sm text-slate-600">Už účet máte? <a href="{{ route('storefront.customers.login') }}" class="text-brand underline">Přihlaste se</a>.</p>
    </div>
@endsection
