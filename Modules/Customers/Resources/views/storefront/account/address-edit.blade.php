@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Upravit adresu</h1>

    <form method="POST" action="{{ route('storefront.customers.account.addresses.update', $address) }}" class="card mt-6 max-w-md space-y-4 p-6">
        @csrf
        @method('PUT')
        @include('customers::storefront.account.partials.address-fields')

        <button type="submit" class="btn btn-primary w-full">Uložit adresu</button>
    </form>

    <p class="mt-4 text-sm">
        <a href="{{ route('storefront.customers.account.addresses') }}" class="text-slate-600 underline hover:text-brand">Zpět na adresy</a>
    </p>
@endsection
