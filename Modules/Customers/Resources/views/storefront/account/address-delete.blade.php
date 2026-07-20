@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Smazat adresu</h1>

    <div class="mt-6 max-w-md rounded border border-red-200 bg-red-50 p-4">
        <p class="text-sm text-red-900">
            Opravdu chcete trvale smazat tuto adresu? Tuto akci nelze vrátit zpět.
        </p>

        <p class="mt-3 text-sm">{{ $address->street }}</p>
        <p class="text-sm">{{ $address->zip }} {{ $address->city }}, {{ $address->country }}</p>
    </div>

    {{--
        The confirmation itself is this whole extra page, not a JavaScript
        confirm() dialog: the destructive action only fires from a real
        server-side form submission, so it works with JavaScript disabled.
    --}}
    <form method="POST" action="{{ route('storefront.customers.account.addresses.destroy', $address) }}" class="mt-4 flex gap-4">
        @csrf
        @method('DELETE')
        <button type="submit" class="rounded bg-red-700 px-4 py-2 text-white">Ano, smazat adresu</button>
        <a href="{{ route('storefront.customers.account.addresses') }}"
           class="rounded border border-slate-300 px-4 py-2 text-sm">Zrušit</a>
    </form>
@endsection
