{{-- Categories as pictures with the name under them, not as buttons. --}}
<section class="mb-12">
    @if (!empty($heading))
        <h2 class="mb-8 text-center text-xl">{{ $heading }}</h2>
    @endif

    <ul class="grid grid-cols-2 gap-x-2 gap-y-6 md:grid-cols-4">
        @foreach ($categories as $category)
            <li>
                <a href="{{ $category->url() }}" class="group block focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                    <span class="block aspect-[3/4] w-full bg-surface-muted" aria-hidden="true"></span>
                    <span class="mt-3 block text-center text-sm uppercase tracking-[0.14em] text-ink group-hover:underline">
                        {{ $category->name }}
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
