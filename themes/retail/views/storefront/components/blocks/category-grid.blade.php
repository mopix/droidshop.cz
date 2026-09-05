{{-- Categories as tiles with a picture area and the name inside the card, the
     way a catalogue shop shows departments. --}}
<section class="mb-10">
    @if (!empty($heading))
        <h2 class="mb-6 text-center text-xl">{{ $heading }}</h2>
    @endif

    <ul class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach ($categories as $category)
            <li>
                <a href="{{ $category->url() }}"
                   class="card block h-full p-3 transition hover:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                    <span class="mb-3 block aspect-[4/3] w-full rounded-token bg-surface-muted" aria-hidden="true"></span>
                    <span class="block text-center text-sm font-semibold text-ink">{{ $category->name }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
