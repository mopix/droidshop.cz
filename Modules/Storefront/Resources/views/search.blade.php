@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">
        @if ($term === '')
            Vyhledávání
        @else
            Vyhledávání: {{ $term }}
        @endif
    </h1>

    @if ($tooShort)
        <p class="mt-4 text-slate-600">Zadejte alespoň dva znaky.</p>
    @elseif ($products->total() === 0)
        {{-- The merchant's own wording if they wrote one (wave 3.6). --}}
        <div class="card mt-6 p-6 text-slate-600">
            {{ $emptyText }}
        </div>
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
