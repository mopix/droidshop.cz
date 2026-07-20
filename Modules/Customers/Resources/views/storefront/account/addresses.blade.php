@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Moje adresy</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('status') }}
        </p>
    @endif

    @if ($addresses->isEmpty())
        <p class="mt-4 text-slate-600">Zatím jste nepřidali žádnou adresu.</p>
    @else
        <ul class="mt-6 space-y-4">
            @foreach ($addresses as $address)
                <li class="rounded border border-slate-200 p-4">
                    <p class="text-sm font-medium">
                        {{ $address->kind === 'billing' ? 'Fakturační adresa' : 'Doručovací adresa' }}
                        @if ($address->is_default)
                            <span class="ml-1 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">výchozí</span>
                        @endif
                    </p>
                    @if ($address->company)
                        <p class="text-sm">{{ $address->company }}</p>
                    @endif
                    <p class="text-sm">{{ $address->street }}</p>
                    <p class="text-sm">{{ $address->zip }} {{ $address->city }}, {{ $address->country }}</p>

                    <div class="mt-3 flex gap-4 text-sm">
                        <a href="{{ route('storefront.customers.account.addresses.edit', $address) }}" class="underline">Upravit</a>
                        <a href="{{ route('storefront.customers.account.addresses.delete', $address) }}" class="text-red-700 underline">Smazat</a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="mt-10 text-lg font-semibold">Přidat adresu</h2>

    <form method="POST" action="{{ route('storefront.customers.account.addresses.store') }}" class="mt-4 max-w-md space-y-4">
        @csrf
        @include('customers::storefront.account.partials.address-fields')

        <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Přidat adresu</button>
    </form>

    <p class="mt-4 text-sm">
        <a href="{{ route('storefront.customers.account') }}" class="underline">Zpět na účet</a>
    </p>
@endsection
