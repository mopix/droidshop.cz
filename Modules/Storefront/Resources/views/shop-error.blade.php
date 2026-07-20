@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">{{ $heading }}</h1>
    <p class="mt-4 text-slate-600">{{ $message }}</p>

    <p class="mt-8">
        <a href="{{ $backUrl ?? '/' }}" class="rounded bg-slate-900 px-4 py-2 text-white">
            {{ $backLabel ?? 'Zpět na úvod' }}
        </a>
    </p>
@endsection
