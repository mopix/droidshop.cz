@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">
        @if ($term === '')
            Vyhledávání
        @else
            Vyhledávání: {{ $term }}
        @endif
    </h1>

    @if ($tooShort)
        <p class="mt-4 text-slate-600">Zadejte alespoň dva znaky.</p>
    @elseif ($products->total() === 0)
        <p class="mt-4 text-slate-600">Nic jsme nenašli. Zkuste jiný výraz nebo procházejte kategorie.</p>
    @else
        <p class="mt-2 text-sm text-slate-600">Nalezeno {{ $products->total() }} produktů.</p>

        <div class="mt-6">
            <x-storefront::product-grid :products="$products" />
        </div>

        <div class="mt-8">
            {{ $products->links() }}
        </div>
    @endif
@endsection
