@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-md py-16 text-center">
        <h1 class="text-2xl font-semibold text-slate-900">{{ $heading }}</h1>
        <p class="mt-4 text-slate-600">{{ $message }}</p>

        <p class="mt-8">
            <a href="{{ $backUrl ?? '/' }}" class="btn btn-primary">
                {{ $backLabel ?? 'Zpět na úvod' }}
            </a>
        </p>
    </div>
@endsection
