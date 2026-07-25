@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Moje údaje</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ session('status') }}
        </p>
    @endif

    <form method="POST" action="{{ route('storefront.customers.account.profile.update') }}" class="card mt-6 max-w-md space-y-4 p-6">
        @csrf
        @method('PUT')

        <div>
            <label for="first_name" class="field-label">Jméno</label>
            <input id="first_name" name="first_name" type="text" value="{{ old('first_name', $customer->first_name) }}"
                   required autocomplete="given-name"
                   @error('first_name') aria-invalid="true" aria-describedby="first_name-error" @enderror
                   class="field-input">
            @error('first_name') <p id="first_name-error" role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="last_name" class="field-label">Příjmení</label>
            <input id="last_name" name="last_name" type="text" value="{{ old('last_name', $customer->last_name) }}"
                   required autocomplete="family-name"
                   @error('last_name') aria-invalid="true" aria-describedby="last_name-error" @enderror
                   class="field-input">
            @error('last_name') <p id="last_name-error" role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="phone" class="field-label">Telefon <span class="font-normal text-slate-500">(nepovinné)</span></label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone', $customer->phone) }}" autocomplete="tel"
                   @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror
                   class="field-input">
            @error('phone') <p id="phone-error" role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <fieldset class="rounded-lg border border-slate-200 p-4">
            <legend class="px-1 text-sm font-medium text-slate-900">Změna hesla <span class="font-normal text-slate-500">(nepovinné)</span></legend>

            <div class="mt-2">
                <label for="current_password" class="field-label">Současné heslo</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password"
                       @error('current_password') aria-invalid="true" aria-describedby="current_password-error" @enderror
                       class="field-input">
                @error('current_password') <p id="current_password-error" role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="mt-3">
                <label for="password" class="field-label">Nové heslo</label>
                <input id="password" name="password" type="password" autocomplete="new-password"
                       @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                       class="field-input">
                @error('password') <p id="password-error" role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="mt-3">
                <label for="password_confirmation" class="field-label">Nové heslo znovu</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                       class="field-input">
            </div>
        </fieldset>

        <button type="submit" class="btn btn-primary w-full">Uložit</button>
    </form>

    <p class="mt-4 text-sm">
        <a href="{{ route('storefront.customers.account') }}" class="text-slate-600 underline hover:text-brand">Zpět na účet</a>
    </p>
@endsection
