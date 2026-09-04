<section class="prose-shop mx-auto mb-12 max-w-2xl">
    @if (!empty($heading))
        <h2 class="mb-4 text-xl">{{ $heading }}</h2>
    @endif
    {!! $html ?? '' !!} {{-- sanitised at write time --}}
</section>
