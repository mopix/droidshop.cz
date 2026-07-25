@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Zapomenuté heslo</h1>

        <p class="mt-2 text-sm text-slate-600">
            Zadejte e-mailovou adresu, na kterou vám pošleme odkaz pro obnovení hesla.
        </p>

        @if (session('status'))
            <p role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                {{ session('status') }}
            </p>
        @endif

        <form method="POST" action="{{ route('storefront.customers.password.email') }}" class="card mt-6 space-y-4 p-6">
            @csrf

            <div>
                <label for="email" class="field-label">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                       class="field-input">
                @error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full">Odeslat odkaz</button>
        </form>

        <p class="mt-4 text-sm">
            <a href="{{ route('storefront.customers.login') }}" class="text-slate-600 underline hover:text-brand">Zpět na přihlášení</a>
        </p>
    </div>
@endsection
