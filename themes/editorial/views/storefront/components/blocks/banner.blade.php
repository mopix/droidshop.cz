<section class="bleed mb-12">
    @if (!empty($image_path))
        @php($bannerUrl = app(\App\Core\Storage\FileStorage::class)->publicUrl($image_path))
        @if (!empty($url))
            <a href="{{ $url }}" @if(empty($alt)) aria-label="Bannerový odkaz" @endif class="block">
                <img src="{{ $bannerUrl }}" alt="{{ $alt ?? '' }}" loading="lazy" class="w-full">
            </a>
        @else
            <img src="{{ $bannerUrl }}" alt="{{ $alt ?? '' }}" loading="lazy" class="w-full">
        @endif
    @endif
</section>
