@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Upravit adresu</h1>

    <form method="POST" action="{{ route('storefront.customers.account.addresses.update', $address) }}" class="mt-6 max-w-md space-y-4">
        @csrf
        @method('PUT')
        @include('customers::storefront.account.partials.address-fields')

        <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Uložit adresu</button>
    </form>

    <p class="mt-4 text-sm">
        <a href="{{ route('storefront.customers.account.addresses') }}" class="underline">Zpět na adresy</a>
    </p>
@endsection
