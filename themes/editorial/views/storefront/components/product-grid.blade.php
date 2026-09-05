@props(['products'])

{{--
    Tight gutters, three across on a wide screen. Two on a phone rather than
    one: with no card chrome, a single column of full-width photographs turns
    a category into an endless scroll.
--}}
<ul class="grid grid-cols-2 gap-x-2 gap-y-8 lg:grid-cols-3 xl:grid-cols-4">
    @foreach ($products as $product)
        <li>
            <x-storefront::product-card :product="$product" />
        </li>
    @endforeach
</ul>
