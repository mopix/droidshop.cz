@props(['items'])

@php
    $trail = collect($items)->values();
@endphp

<nav aria-label="Drobečková navigace" class="mb-6 text-sm text-slate-600">
    <ol class="flex flex-wrap gap-2">
        @foreach ($trail as $index => $item)
            <li class="flex items-center gap-2">
                @if ($index < $trail->count() - 1)
                    <a href="{{ $item['url'] }}" class="hover:underline">{{ $item['label'] }}</a>
                    <span aria-hidden="true">/</span>
                @else
                    <span aria-current="page">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

@push('head')
    <x-storefront::json-ld :data="[
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $trail->map(fn ($item, $index) => [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'name' => $item['label'],
            'item' => url($item['url']),
        ])->all(),
    ]" />
@endpush
