<section class="py-8" @if(!empty($heading)) aria-labelledby="row-heading-{{ $id }}" @endif>
    @if (!empty($heading))
        <h2 id="row-heading-{{ $id }}" class="mb-6 text-lg font-semibold text-slate-900">{{ $heading }}</h2>
    @endif

    @if ($products->isEmpty())
        <p class="text-slate-600">Nabídka se právě připravuje.</p>
    @else
        <x-storefront::product-grid :products="$products" />
    @endif
</section>
