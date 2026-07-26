@extends('storefront::layouts.shop')

@section('content')
    @forelse ($blocks as $block)
        @includeFirst(
            ['storefront::components.blocks.'.$block['type']],
            $block['data']
        )
    @empty
        <p class="text-slate-600">Nabídka se právě připravuje.</p>
    @endforelse
@endsection

@push('head')
    <x-storefront::json-ld :data="[
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $shopName,
        'url' => url('/'),
    ]" />
    <x-storefront::json-ld :data="[
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $shopName,
        'url' => url('/'),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => url('/hledani').'?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ]" />
@endpush
