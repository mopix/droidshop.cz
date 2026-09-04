@props(['products'])

{{-- Three across at most: a bordered card needs room for its facts, and a
     fourth column squeezes the price and the button onto separate lines. --}}
<ul class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ($products as $product)
        <li>
            <x-storefront::product-card :product="$product" />
        </li>
    @endforeach
</ul>
