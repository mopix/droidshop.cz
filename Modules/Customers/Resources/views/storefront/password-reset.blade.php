@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Obnovení hesla</h1>

        <form method="POST" action="{{ route('storefront.customers.password.update') }}" class="card mt-6 space-y-4 p-6">
            @csrf

            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label for="email" class="field-label">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required readonly autocomplete="email"
                       class="field-input bg-slate-100">
                @error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="field-label">Nové heslo</label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                       class="field-input">
                @error('password') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="field-label">Nové heslo znovu</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                       class="field-input">
            </div>

            <button type="submit" class="btn btn-primary w-full">Změnit heslo</button>
        </form>
    </div>
@endsection
