<section class="mb-10" @if(!empty($heading)) aria-labelledby="row-heading-{{ $id }}" @endif>
    @if (!empty($heading))
        <h2 id="row-heading-{{ $id }}" class="mb-6 text-center text-xl">{{ $heading }}</h2>
    @endif

    @if ($products->isEmpty())
        <p class="text-center text-ink-muted">Nabídka se právě připravuje.</p>
    @else
        <x-storefront::product-grid :products="$products" />
    @endif
</section>
