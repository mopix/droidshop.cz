@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Zapomenuté heslo</h1>

    <p class="mt-2 text-sm text-slate-600">
        Zadejte e-mailovou adresu, na kterou vám pošleme odkaz pro obnovení hesla.
    </p>

    @if (session('status'))
        <p role="status" class="mt-4 rounded border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('status') }}
        </p>
    @endif

    <form method="POST" action="{{ route('storefront.customers.password.email') }}" class="mt-6 max-w-md space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            @error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Odeslat odkaz</button>
    </form>

    <p class="mt-4 text-sm">
        <a href="{{ route('storefront.customers.login') }}" class="underline">Zpět na přihlášení</a>
    </p>
@endsection
