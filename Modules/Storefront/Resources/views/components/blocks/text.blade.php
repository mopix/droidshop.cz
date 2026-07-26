<section class="prose max-w-none py-8">
    @if (!empty($heading))
        <h2 class="text-lg font-semibold text-slate-900">{{ $heading }}</h2>
    @endif
    {!! $html ?? '' !!} {{-- sanitized at write time (Task 5) --}}
</section>
