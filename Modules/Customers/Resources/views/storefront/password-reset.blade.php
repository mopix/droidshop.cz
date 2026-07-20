@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Obnovení hesla</h1>

    <form method="POST" action="{{ route('storefront.customers.password.update') }}" class="mt-6 max-w-md space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="block text-sm font-medium">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">Nové heslo</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('password') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">Nové heslo znovu</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </div>

        <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Změnit heslo</button>
    </form>
@endsection
