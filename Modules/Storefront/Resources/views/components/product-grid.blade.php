@props(['products'])

<ul class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ($products as $product)
        <li>
            <x-storefront::product-card :product="$product" />
        </li>
    @endforeach
</ul>
