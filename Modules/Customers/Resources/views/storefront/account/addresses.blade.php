@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Moje adresy</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ session('status') }}
        </p>
    @endif

    @if ($addresses->isEmpty())
        <div class="card mt-4 p-6 text-slate-600">Zatím jste nepřidali žádnou adresu.</div>
    @else
        <ul class="mt-6 grid gap-4 sm:grid-cols-2">
            @foreach ($addresses as $address)
                <li class="card p-4">
                    <p class="text-sm font-medium text-slate-900">
                        {{ $address->kind === 'billing' ? 'Fakturační adresa' : 'Doručovací adresa' }}
                        @if ($address->is_default)
                            <span class="badge ml-1 bg-slate-100 text-slate-600">výchozí</span>
                        @endif
                    </p>
                    @if ($address->company)
                        <p class="mt-1 text-sm text-slate-700">{{ $address->company }}</p>
                    @endif
                    <p class="mt-1 text-sm text-slate-700">{{ $address->street }}</p>
                    <p class="text-sm text-slate-700">{{ $address->zip }} {{ $address->city }}, {{ $address->country }}</p>

                    <div class="mt-3 flex gap-4 text-sm">
                        <a href="{{ route('storefront.customers.account.addresses.edit', $address) }}" class="text-slate-700 underline hover:text-brand">Upravit</a>
                        <a href="{{ route('storefront.customers.account.addresses.delete', $address) }}" class="text-red-700 underline hover:text-red-800">Smazat</a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="mt-10 text-lg font-semibold text-slate-900">Přidat adresu</h2>

    <form method="POST" action="{{ route('storefront.customers.account.addresses.store') }}" class="card mt-4 max-w-md space-y-4 p-6">
        @csrf
        {{--
            $address must be passed explicitly as null here: @foreach leaves
            $address bound to the last iterated item in this view's scope,
            and @include inherits the parent view's variables. Without this,
            the "add address" form would silently prefill from whichever
            address was listed last, is_default checkbox included.
        --}}
        @include('customers::storefront.account.partials.address-fields', ['address' => null])

        <button type="submit" class="btn btn-primary w-full">Přidat adresu</button>
    </form>

    <p class="mt-4 text-sm">
        <a href="{{ route('storefront.customers.account') }}" class="text-slate-600 underline hover:text-brand">Zpět na účet</a>
    </p>
@endsection
