<section class="mb-10">
    <ul class="grid gap-4 sm:grid-cols-{{ min(count($banners), 3) }}">
        @foreach ($banners as $banner)
            @continue(empty($banner['image_path']))

            @php($url = app(\App\Core\Storage\FileStorage::class)->publicUrl($banner['image_path']))
            <li>
                @if (!empty($banner['url']))
                    <a href="{{ $banner['url'] }}" @if(empty($banner['alt'])) aria-label="Bannerový odkaz" @endif class="block">
                        <img src="{{ $url }}" alt="{{ $banner['alt'] ?? '' }}" loading="lazy" class="w-full rounded-token-lg">
                    </a>
                @else
                    <img src="{{ $url }}" alt="{{ $banner['alt'] ?? '' }}" loading="lazy" class="w-full rounded-token-lg">
                @endif
            </li>
        @endforeach
    </ul>
</section>
