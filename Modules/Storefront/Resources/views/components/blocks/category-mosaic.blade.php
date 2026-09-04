{{--
    An asymmetric arrangement: the layout is a shape, not data. "2-2" is four
    equal tiles, "1-2-1" a tall tile, two stacked halves and a tall tile —
    whichever categories the shop puts in them.
--}}
@php
    $categories = $categories->values();
    $tall = $layout === '1-2-1' ? [0, 3] : [];
@endphp

<section class="mb-10">
    @if (!empty($heading))
        <h2 class="mb-6 text-center text-xl">{{ $heading }}</h2>
    @endif

    <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($categories as $index => $category)
            <li class="{{ in_array($index, $tall, true) ? 'lg:row-span-2' : '' }}">
                <a href="{{ $category->url() }}"
                   class="card group flex h-full flex-col overflow-hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                    <span class="block flex-1 bg-surface-muted {{ in_array($index, $tall, true) ? 'aspect-[3/4] lg:aspect-auto lg:min-h-64' : 'aspect-[4/3]' }}"
                          aria-hidden="true"></span>
                    <span class="block p-3 text-center text-sm font-semibold text-ink group-hover:underline">
                        {{ $category->name }}
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
