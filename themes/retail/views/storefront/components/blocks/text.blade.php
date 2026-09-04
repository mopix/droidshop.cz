<section class="card prose-shop mb-10 p-6">
    @if (!empty($heading))
        <h2 class="mb-3 text-xl">{{ $heading }}</h2>
    @endif
    {!! $html ?? '' !!} {{-- sanitised at write time --}}
</section>
