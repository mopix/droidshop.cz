@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Moje údaje</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('status') }}
        </p>
    @endif

    <form method="POST" action="{{ route('storefront.customers.account.profile.update') }}" class="mt-6 max-w-md space-y-4">
        @csrf
        @method('PUT')

        <div>
            <label for="first_name" class="block text-sm font-medium">Jméno</label>
            <input id="first_name" name="first_name" type="text" value="{{ old('first_name', $customer->first_name) }}"
                   required autocomplete="given-name" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('first_name') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="last_name" class="block text-sm font-medium">Příjmení</label>
            <input id="last_name" name="last_name" type="text" value="{{ old('last_name', $customer->last_name) }}"
                   required autocomplete="family-name" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('last_name') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium">Telefon <span class="font-normal text-slate-500">(nepovinné)</span></label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone', $customer->phone) }}" autocomplete="tel"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('phone') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <fieldset class="rounded border border-slate-200 p-4">
            <legend class="px-1 text-sm font-medium">Změna hesla <span class="font-normal text-slate-500">(nepovinné)</span></legend>

            <div class="mt-2">
                <label for="current_password" class="block text-sm font-medium">Současné heslo</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                @error('current_password') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="mt-3">
                <label for="password" class="block text-sm font-medium">Nové heslo</label>
                <input id="password" name="password" type="password" autocomplete="new-password"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                @error('password') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="mt-3">
                <label for="password_confirmation" class="block text-sm font-medium">Nové heslo znovu</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            </div>
        </fieldset>

        <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Uložit</button>
    </form>

    <p class="mt-4 text-sm">
        <a href="{{ route('storefront.customers.account') }}" class="underline">Zpět na účet</a>
    </p>
@endsection
